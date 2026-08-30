<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Events\ReconciliationFinalized;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\DB;

final class FinalizeReconciliation
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $splits
     */
    public function handle(BankStatementLine $line, array $splits, ?string $reason = null, ?string $idempotencyKey = null): Reconciliation
    {
        $this->authorizer->authorize('finalize_reconciliation', $line);
        $this->scope->assertSame((int) $line->legal_entity_id);

        return DB::transaction(function () use ($line, $splits, $reason, $idempotencyKey): Reconciliation {
            $line = BankStatementLine::query()->lockForUpdate()->with('bankAccount')->findOrFail($line->getKey());
            $this->scope->assertSame((int) $line->legal_entity_id);

            $key = $idempotencyKey ?: 'reconciliation:'.$line->getKey();
            $existing = Reconciliation::query()
                ->where('legal_entity_id', $line->legal_entity_id)
                ->where('idempotency_key', $key)
                ->where('status', ReconciliationStatus::Posted)
                ->first();

            if ($existing instanceof Reconciliation) {
                return $existing;
            }

            $posted = Reconciliation::query()
                ->where('statement_line_id', $line->getKey())
                ->where('status', ReconciliationStatus::Posted)
                ->lockForUpdate()
                ->first();

            if ($posted instanceof Reconciliation) {
                throw new ReconciliationException(__('filament-accounting::errors.already_reconciled'));
            }

            if ($line->source_status !== StatementLineStatus::Booked && $reason === null) {
                throw new ReconciliationException(__('filament-accounting::errors.pending_cannot_finalize'));
            }

            $sum = 0;
            foreach ($splits as $split) {
                $sum += (int) ($split['amount_minor'] ?? 0);
            }

            if ($sum !== (int) $line->amount_minor) {
                throw new ReconciliationException(__('filament-accounting::errors.reconciliation_imbalance'));
            }

            $actor = $this->actors->resolve();
            $entity = LegalEntity::query()->findOrFail($line->legal_entity_id);

            $reconciliation = new Reconciliation;
            $reconciliation->fill([
                'legal_entity_id' => $line->legal_entity_id,
                'statement_line_id' => $line->getKey(),
                'status' => ReconciliationStatus::Ready,
                'version' => 1,
                'idempotency_key' => $key,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor ? (string) $actor->getKey() : null,
                'reason' => $reason,
            ]);
            $reconciliation->save();

            foreach ($splits as $input) {
                $split = new ReconciliationSplit;
                $split->fill([
                    'reconciliation_id' => $reconciliation->getKey(),
                    'purpose' => SplitPurpose::from((string) $input['purpose']),
                    'amount_minor' => (int) $input['amount_minor'],
                    'currency' => $line->currency,
                    'open_item_id' => $input['open_item_id'] ?? null,
                    'posting_rule_version_id' => $input['posting_rule_version_id'] ?? null,
                    'ledger_account_id' => $input['ledger_account_id'] ?? null,
                    'reason' => $input['reason'] ?? null,
                ]);
                $split->save();
            }

            $journalLines = $this->buildJournalLines($entity, $line, $reconciliation->fresh(['splits.openItem']) ?? $reconciliation);
            $entry = $this->ledger->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: ($line->booking_date ?? now())->toDateString(),
                sourceType: 'reconciliation',
                sourceId: (string) $reconciliation->getKey(),
                currency: (string) $line->currency,
                baseCurrency: (string) $entity->base_currency,
                lines: $journalLines,
                description: $line->purpose,
                idempotencyKey: 'journal:'.$key,
                postedByType: $actor?->getMorphClass(),
                postedById: $actor ? (string) $actor->getKey() : null,
            ));

            foreach ($reconciliation->splits()->get() as $split) {
                if (! $split instanceof ReconciliationSplit) {
                    continue;
                }
                if ($split->purpose === SplitPurpose::SettleOpenItem && $split->open_item_id) {
                    $item = OpenItem::query()->lockForUpdate()->findOrFail($split->open_item_id);
                    if ((int) $item->legal_entity_id !== (int) $line->legal_entity_id) {
                        throw new ReconciliationException(__('filament-accounting::errors.entity_mismatch'));
                    }

                    $amount = abs((int) $split->amount_minor);
                    $remaining = abs($item->remainingMinor());
                    if ($amount > $remaining) {
                        throw new ReconciliationException(__('filament-accounting::errors.settlement_exceeds_remaining'));
                    }

                    $settlement = new Settlement;
                    $settlement->fill([
                        'legal_entity_id' => $line->legal_entity_id,
                        'open_item_id' => $item->getKey(),
                        'journal_entry_id' => $entry->getKey(),
                        'amount_minor' => $amount,
                        'currency' => $line->currency,
                        'is_reversed' => false,
                    ]);
                    $settlement->save();
                }
            }

            $reconciliation->status = ReconciliationStatus::Posted;
            $reconciliation->journal_entry_id = $entry->getKey();
            $reconciliation->finalized_at = now();
            $reconciliation->save();

            $this->audit->log($entity, 'reconciliation.finalized', $reconciliation, [
                'statement_line_id' => $line->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            DB::afterCommit(fn () => ReconciliationFinalized::dispatch($reconciliation->fresh(['splits', 'journalEntry'])));

            return $reconciliation->fresh(['splits', 'journalEntry']) ?? $reconciliation;
        });
    }

    /**
     * @return list<JournalLineDraft>
     */
    private function buildJournalLines(LegalEntity $entity, BankStatementLine $line, Reconciliation $reconciliation): array
    {
        $currency = (string) $line->currency;
        $bankAccountId = (int) $line->bankAccount->ledger_account_id;
        $amount = (int) $line->amount_minor;
        $drafts = [];

        if ($amount > 0) {
            $drafts[] = JournalLineDraft::debit($bankAccountId, $amount, $currency, $line->purpose);
        } elseif ($amount < 0) {
            $drafts[] = JournalLineDraft::credit($bankAccountId, abs($amount), $currency, $line->purpose);
        }

        foreach ($reconciliation->splits as $split) {
            $splitAmount = abs((int) $split->amount_minor);
            if ($splitAmount === 0) {
                continue;
            }

            $accountId = $this->counterpartAccount($entity, $line, $split);
            $incoming = (int) $split->amount_minor > 0;

            if ($incoming) {
                $drafts[] = JournalLineDraft::credit($accountId, $splitAmount, $currency, $split->reason);
            } else {
                $drafts[] = JournalLineDraft::debit($accountId, $splitAmount, $currency, $split->reason);
            }
        }

        return $drafts;
    }

    private function counterpartAccount(LegalEntity $entity, BankStatementLine $line, ReconciliationSplit $split): int
    {
        if ($split->ledger_account_id) {
            return (int) $split->ledger_account_id;
        }

        if ($split->purpose === SplitPurpose::SettleOpenItem && $split->openItem instanceof OpenItem) {
            $role = $split->openItem->kind === OpenItemKind::Receivable
                ? AccountRole::Receivable
                : AccountRole::Payable;

            return $this->roleAccount($entity, $role);
        }

        if ($split->purpose === SplitPurpose::BankFee) {
            return $this->roleAccount($entity, AccountRole::Expense);
        }

        if ($split->purpose === SplitPurpose::Suspense) {
            return $this->roleAccount($entity, AccountRole::Suspense);
        }

        if ($split->purpose === SplitPurpose::Rounding) {
            return $this->roleAccount($entity, AccountRole::Rounding);
        }

        if ($split->posting_rule_version_id && $split->postingRuleVersion) {
            $mappings = $split->postingRuleVersion->account_mappings ?? [];
            $role = $mappings['counterpart'] ?? $mappings['expense'] ?? $mappings['revenue'] ?? null;
            if (is_string($role)) {
                return $this->roleAccount($entity, AccountRole::from($role));
            }
            if (isset($mappings['ledger_account_id'])) {
                return (int) $mappings['ledger_account_id'];
            }
        }

        return $this->roleAccount($entity, $line->isIncoming() ? AccountRole::Revenue : AccountRole::Expense);
    }

    private function roleAccount(LegalEntity $entity, AccountRole $role): int
    {
        $assignment = AccountRoleAssignment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('role', $role->value)
            ->first();

        if (! $assignment instanceof AccountRoleAssignment) {
            throw new ReconciliationException(__('filament-accounting::errors.missing_account_role', ['role' => $role->value]));
        }

        return (int) $assignment->ledger_account_id;
    }
}
