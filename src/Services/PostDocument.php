<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Events\DocumentPosted;
use FilamentAccounting\Exceptions\CurrencyMismatchException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;

final class PostDocument
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly CreateOpenItem $openItems,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Document $document): Document
    {
        $this->authorizer->authorize('post_documents', $document);

        return $document->getConnection()->transaction(function () use ($document): Document {
            LegalEntity::query()->lockForUpdate()->findOrFail($document->getRawOriginal('legal_entity_id'));
            $document = Document::query()->lockForUpdate()->with('lines')->findOrFail($document->getKey());
            $this->authorizer->authorize('post_documents', $document);
            if ($document->posting_status === PostingStatus::Posted) {
                return $document;
            }
            if ($document->posting_status !== PostingStatus::Unposted || ! $this->isPostable($document)) {
                throw new DocumentException(__('filament-accounting::errors.document_not_ready_to_post'));
            }
            $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
            if (strtoupper((string) $document->currency) !== strtoupper((string) $entity->base_currency)) {
                throw new CurrencyMismatchException(__('filament-accounting::errors.foreign_currency_unsupported'));
            }

            $actor = $this->actors->resolve();
            $lines = $this->journalLines($entity, $document);

            $this->ledger->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: ($document->issue_date ?? now())->toDateString(),
                sourceType: 'document',
                sourceId: (string) $document->getKey(),
                currency: (string) $document->currency,
                baseCurrency: (string) $entity->base_currency,
                lines: $lines,
                description: $document->number,
                exchangeRate: $document->exchange_rate,
                idempotencyKey: 'document:'.$document->getKey(),
                postedByType: $actor?->getMorphClass(),
                postedById: $actor ? (string) $actor->getKey() : null,
            ));

            $document->posting_status = PostingStatus::Posted;
            $document->posted_at = now();
            $document->save();

            $this->openItems->handle($document);
            $this->audit->log($entity, 'document.posted', $document, [
                'number' => $document->number,
            ]);

            $document->getConnection()->afterCommit(fn () => DocumentPosted::dispatch($document->fresh()));

            return $document->fresh(['lines', 'openItem']) ?? $document;
        });
    }

    private function isPostable(Document $document): bool
    {
        return match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::SalesCreditNote => $document->document_status === DocumentStatus::Issued,
            DocumentType::PurchaseInvoice, DocumentType::PurchaseCreditNote => $document->document_status === DocumentStatus::Received,
        };
    }

    /**
     * @return list<JournalLineDraft>
     */
    private function journalLines(LegalEntity $entity, Document $document): array
    {
        $currency = (string) $document->currency;
        $creditNote = $document->type->isCreditNote();
        $sales = in_array($document->type, [DocumentType::SalesInvoice, DocumentType::SalesCreditNote], true);
        $gross = (int) $document->gross_minor;
        $drafts = [];

        $receivable = $this->accountForRole($entity, AccountRole::Receivable);
        $payable = $this->accountForRole($entity, AccountRole::Payable);
        $revenue = $this->accountForRole($entity, AccountRole::Revenue);
        $expense = $this->accountForRole($entity, AccountRole::Expense);
        $outputTax = $this->accountForRole($entity, AccountRole::OutputTax);
        $inputTax = $this->accountForRole($entity, AccountRole::InputTax);

        if ($sales) {
            $drafts[] = $this->signed(! $creditNote, $receivable, $gross, $currency, $document->number);
            foreach ($this->netByAccount($document, $revenue, foldUnrecoverableTax: false) as $accountId => $amount) {
                if ($amount !== 0) {
                    $drafts[] = $this->signed($creditNote, (int) $accountId, $amount, $currency, $document->number);
                }
            }
            foreach ($this->taxGroups($document, onlyRecoverable: false) as $group) {
                $drafts[] = $this->signed(
                    $creditNote,
                    $outputTax,
                    $group['amount'],
                    $currency,
                    $document->number,
                    $group['tax_code'],
                    $group['tax_rule_version_id'],
                );
            }
        } else {
            foreach ($this->netByAccount($document, $expense, foldUnrecoverableTax: true) as $accountId => $amount) {
                if ($amount !== 0) {
                    $drafts[] = $this->signed(! $creditNote, (int) $accountId, $amount, $currency, $document->number);
                }
            }
            foreach ($this->taxGroups($document, onlyRecoverable: true) as $group) {
                $drafts[] = $this->signed(
                    ! $creditNote,
                    $inputTax,
                    $group['amount'],
                    $currency,
                    $document->number,
                    $group['tax_code'],
                    $group['tax_rule_version_id'],
                );
            }
            $drafts[] = $this->signed($creditNote, $payable, $gross, $currency, $document->number);
        }

        if (count($drafts) < 2) {
            throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
        }

        return $drafts;
    }

    /**
     * @return array<int, int>
     */
    private function netByAccount(Document $document, int $fallbackAccountId, bool $foldUnrecoverableTax): array
    {
        $netByAccount = [];
        foreach ($document->lines as $line) {
            $accountId = $line->ledger_account_id ? (int) $line->ledger_account_id : $fallbackAccountId;
            $amount = (int) $line->net_minor;
            if ($foldUnrecoverableTax && $line->tax_recoverable === false) {
                $amount += (int) $line->tax_minor;
            }
            $netByAccount[$accountId] = ($netByAccount[$accountId] ?? 0) + $amount;
        }

        return $netByAccount;
    }

    /**
     * @return list<array{amount: int, tax_code: string, tax_rule_version_id: int}>
     */
    private function taxGroups(Document $document, bool $onlyRecoverable): array
    {
        return $document->lines
            ->filter(function ($line) use ($onlyRecoverable): bool {
                if ((int) $line->tax_minor === 0) {
                    return false;
                }

                return ! ($onlyRecoverable && $line->tax_recoverable === false);
            })
            ->groupBy(fn ($line): string => implode('|', [
                (string) $line->tax_code,
                (string) $line->tax_rule_version_id,
                (string) $line->tax_rate_bp,
            ]))
            ->map(fn ($lines): array => [
                'amount' => (int) $lines->sum('tax_minor'),
                'tax_code' => (string) $lines->first()->tax_code,
                'tax_rule_version_id' => (int) $lines->first()->tax_rule_version_id,
            ])
            ->values()
            ->all();
    }

    private function signed(
        bool $debit,
        int $accountId,
        int $minor,
        string $currency,
        ?string $description,
        ?string $taxCode = null,
        ?int $taxRuleVersionId = null,
    ): JournalLineDraft {
        return $debit
            ? JournalLineDraft::debit($accountId, $minor, $currency, $description, $taxCode, $taxRuleVersionId)
            : JournalLineDraft::credit($accountId, $minor, $currency, $description, $taxCode, $taxRuleVersionId);
    }

    private function accountForRole(LegalEntity $entity, AccountRole $role): int
    {
        $assignment = AccountRoleAssignment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('role', $role->value)
            ->first();

        if (! $assignment instanceof AccountRoleAssignment) {
            throw new DocumentException(__('filament-accounting::errors.missing_account_role', ['role' => $role->value]));
        }

        return (int) $assignment->ledger_account_id;
    }
}
