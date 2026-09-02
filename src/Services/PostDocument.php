<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Events\DocumentPosted;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Support\Facades\DB;

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

        if ($document->posting_status === PostingStatus::Posted) {
            return $document;
        }

        return DB::transaction(function () use ($document): Document {
            $document = Document::query()->lockForUpdate()->with('lines')->findOrFail($document->getKey());
            $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
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

            DB::afterCommit(fn () => DocumentPosted::dispatch($document->fresh()));

            return $document->fresh(['lines', 'openItem']) ?? $document;
        });
    }

    /**
     * @return list<JournalLineDraft>
     */
    private function journalLines(LegalEntity $entity, Document $document): array
    {
        $currency = (string) $document->currency;
        $drafts = [];

        $receivable = $this->accountForRole($entity, AccountRole::Receivable);
        $payable = $this->accountForRole($entity, AccountRole::Payable);
        $revenue = $this->accountForRole($entity, AccountRole::Revenue);
        $expense = $this->accountForRole($entity, AccountRole::Expense);
        $outputTax = $this->accountForRole($entity, AccountRole::OutputTax);
        $inputTax = $this->accountForRole($entity, AccountRole::InputTax);

        $gross = (int) $document->gross_minor;
        if (in_array($document->type, [DocumentType::SalesInvoice, DocumentType::SalesCreditNote], true)) {
            $netByAccount = [];
            foreach ($document->lines as $line) {
                $accountId = $line->ledger_account_id ? (int) $line->ledger_account_id : $revenue;
                $netByAccount[$accountId] = ($netByAccount[$accountId] ?? 0) + (int) $line->net_minor;
            }

            $drafts[] = JournalLineDraft::debit($receivable, $gross, $currency, $document->number);
            foreach ($netByAccount as $accountId => $amount) {
                if ($amount !== 0) {
                    $drafts[] = JournalLineDraft::credit((int) $accountId, $amount, $currency, $document->number);
                }
            }
            foreach ($this->taxGroups($document) as $group) {
                $drafts[] = JournalLineDraft::credit(
                    $outputTax,
                    $group['amount'],
                    $currency,
                    $document->number,
                    $group['tax_code'],
                    $group['tax_rule_version_id'],
                );
            }
        } else {
            $netByAccount = [];
            foreach ($document->lines as $line) {
                $accountId = $line->ledger_account_id ? (int) $line->ledger_account_id : $expense;
                $netByAccount[$accountId] = ($netByAccount[$accountId] ?? 0) + (int) $line->net_minor;
            }

            foreach ($netByAccount as $accountId => $amount) {
                if ($amount !== 0) {
                    $drafts[] = JournalLineDraft::debit((int) $accountId, $amount, $currency, $document->number);
                }
            }
            foreach ($this->taxGroups($document) as $group) {
                $drafts[] = JournalLineDraft::debit(
                    $inputTax,
                    $group['amount'],
                    $currency,
                    $document->number,
                    $group['tax_code'],
                    $group['tax_rule_version_id'],
                );
            }
            $drafts[] = JournalLineDraft::credit($payable, $gross, $currency, $document->number);
        }

        if (count($drafts) < 2) {
            throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
        }

        return $drafts;
    }

    /**
     * @return list<array{amount: int, tax_code: string, tax_rule_version_id: int}>
     */
    private function taxGroups(Document $document): array
    {
        return $document->lines
            ->filter(fn ($line): bool => (int) $line->tax_minor !== 0)
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

    private function accountForRole(LegalEntity $entity, AccountRole $role): int
    {
        $assignment = AccountRoleAssignment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('role', $role->value)
            ->first();

        if ($assignment instanceof AccountRoleAssignment) {
            return (int) $assignment->ledger_account_id;
        }

        $account = LedgerAccount::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        if (! $account) {
            throw new DocumentException(__('filament-accounting::errors.missing_account_role', ['role' => $role->value]));
        }

        throw new DocumentException(__('filament-accounting::errors.missing_account_role', ['role' => $role->value]));
    }
}
