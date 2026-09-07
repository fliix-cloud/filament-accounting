<?php

namespace FilamentAccounting\Ledger;

use FilamentAccounting\Audit\JournalSnapshot;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Events\JournalPosted;
use FilamentAccounting\Exceptions\ClosedPeriodException;
use FilamentAccounting\Exceptions\UnbalancedJournalException;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\JournalLine;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Services\ResolveAccountingPeriod;
use Illuminate\Database\Eloquent\Collection;

final class FirstPartyLedgerEngine implements LedgerEngine
{
    public function __construct(
        private readonly ResolveAccountingPeriod $periods,
        private readonly AuditLogger $audit,
        private readonly JournalSnapshot $snapshots,
    ) {}

    public function post(PostJournalCommand $command): JournalEntry
    {
        return (new LegalEntity)->getConnection()->transaction(function () use ($command): JournalEntry {
            $entity = LegalEntity::query()->lockForUpdate()->findOrFail($command->legalEntityId);

            if (filled($command->idempotencyKey)) {
                $existing = JournalEntry::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->first();

                if ($existing instanceof JournalEntry) {
                    return $existing;
                }
            }

            $accounts = $this->assertLines($entity, $command->lines);

            $period = $this->periods->covering($entity, $command->postedOn, lock: true);

            if ($period->state === PeriodState::HardClosed) {
                throw new ClosedPeriodException(__('filament-accounting::errors.period_closed'));
            }

            $debits = 0;
            $credits = 0;
            foreach ($command->lines as $line) {
                $debits += $line->baseDebitMinor;
                $credits += $line->baseCreditMinor;
            }

            if ($debits !== $credits) {
                throw new UnbalancedJournalException(__('filament-accounting::errors.unbalanced_journal'));
            }

            $entry = new JournalEntry;
            $entry->fill([
                'legal_entity_id' => $entity->getKey(),
                'sequence' => $this->nextSequence($entity),
                'period_id' => $period->getKey(),
                'period_snapshot' => [
                    'id' => (int) $period->getKey(),
                    'uuid' => (string) $period->uuid,
                    'fiscal_year' => (int) $period->fiscal_year,
                    'period_number' => (int) $period->period_number,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                ],
                'posted_on' => $command->postedOn,
                'status' => JournalStatus::Draft,
                'source_type' => $command->sourceType,
                'source_id' => $command->sourceId,
                'description' => $command->description,
                'currency' => strtoupper($command->currency),
                'base_currency' => strtoupper($command->baseCurrency),
                'exchange_rate' => $command->exchangeRate,
                'posting_rule_version_id' => $command->postingRuleVersionId,
                'reverses_id' => $command->reversesId,
                'idempotency_key' => $command->idempotencyKey,
                'posted_by_type' => $command->postedByType,
                'posted_by_id' => $command->postedById,
                'posted_at' => now(),
            ]);
            $entry->save();

            foreach ($command->lines as $index => $draft) {
                $account = $accounts->find($draft->ledgerAccountId)
                    ?? throw new UnbalancedJournalException(__('filament-accounting::errors.ledger_account_invalid'));
                $line = new JournalLine;
                $line->fill([
                    'journal_entry_id' => $entry->getKey(),
                    'ledger_account_id' => $draft->ledgerAccountId,
                    'account_snapshot' => [
                        'id' => (int) $account->getKey(),
                        'uuid' => (string) $account->uuid,
                        'code' => (string) $account->code,
                        'name' => (string) $account->name,
                        'type' => $account->type->value,
                        'normal_balance' => $account->normal_balance->value,
                        'currency' => $account->currency,
                    ],
                    'position' => $index + 1,
                    'debit_minor' => $draft->debitMinor,
                    'credit_minor' => $draft->creditMinor,
                    'currency' => strtoupper($draft->currency),
                    'base_debit_minor' => $draft->baseDebitMinor,
                    'base_credit_minor' => $draft->baseCreditMinor,
                    'description' => $draft->description,
                    'tax_code' => $draft->taxCode,
                    'tax_rule_version_id' => $draft->taxRuleVersionId,
                ]);
                $line->save();
            }

            $entry->status = JournalStatus::Posted;
            $entry->save();

            // Capture database-normalized values after the final state transition.
            $entry->refresh()->load('lines');
            $snapshot = $this->snapshots->capture($entry);
            $this->audit->log($entity, 'journal.posted', $entry, [
                'sequence' => $entry->sequence,
                'source_type' => $entry->source_type,
                'journal_snapshot' => $snapshot,
                'snapshot_sha256' => $this->snapshots->hash($snapshot),
            ]);

            $entity->getConnection()->afterCommit(fn () => JournalPosted::dispatch($entry->fresh(['lines'])));

            return $entry->fresh(['lines']) ?? $entry;
        });
    }

    public function reverse(ReverseJournalCommand $command): JournalEntry
    {
        return (new JournalEntry)->getConnection()->transaction(function () use ($command): JournalEntry {
            $original = JournalEntry::query()
                ->lockForUpdate()
                ->with('lines')
                ->findOrFail($command->journalEntryId);

            if (filled($command->idempotencyKey)) {
                $existing = JournalEntry::query()
                    ->where('legal_entity_id', $original->legal_entity_id)
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->first();

                if ($existing instanceof JournalEntry) {
                    return $existing;
                }
            }

            $already = JournalEntry::query()
                ->where('reverses_id', $original->getKey())
                ->where('status', JournalStatus::Posted)
                ->first();

            if ($already instanceof JournalEntry) {
                return $already;
            }

            $lines = [];
            foreach ($original->lines as $line) {
                $lines[] = new JournalLineDraft(
                    ledgerAccountId: (int) $line->ledger_account_id,
                    debitMinor: (int) $line->credit_minor,
                    creditMinor: (int) $line->debit_minor,
                    currency: (string) $line->currency,
                    baseDebitMinor: (int) $line->base_credit_minor,
                    baseCreditMinor: (int) $line->base_debit_minor,
                    description: $command->reason ?: $line->description,
                    taxCode: $line->tax_code,
                    taxRuleVersionId: $line->tax_rule_version_id ? (int) $line->tax_rule_version_id : null,
                );
            }

            $reversal = $this->post(new PostJournalCommand(
                legalEntityId: (int) $original->legal_entity_id,
                postedOn: $command->postedOn,
                sourceType: 'reversal',
                sourceId: (string) $original->getKey(),
                currency: (string) $original->currency,
                baseCurrency: (string) $original->base_currency,
                lines: $lines,
                description: $command->reason ?: __('filament-accounting::actions.reverse_journal'),
                exchangeRate: $original->exchange_rate,
                postingRuleVersionId: $original->posting_rule_version_id ? (int) $original->posting_rule_version_id : null,
                idempotencyKey: $command->idempotencyKey,
                postedByType: $command->postedByType,
                postedById: $command->postedById,
                reversesId: (int) $original->getKey(),
            ));

            return $reversal->fresh(['lines']) ?? $reversal;
        });
    }

    /**
     * @param  list<JournalLineDraft>  $lines
     * @return Collection<int, LedgerAccount>
     */
    private function assertLines(LegalEntity $entity, array $lines): Collection
    {
        if (count($lines) < 2) {
            throw new UnbalancedJournalException(__('filament-accounting::errors.journal_min_lines'));
        }

        foreach ($lines as $line) {
            if ($line->debitMinor !== 0 && $line->creditMinor !== 0) {
                throw new UnbalancedJournalException(__('filament-accounting::errors.journal_debit_and_credit'));
            }

            if ($line->debitMinor === 0 && $line->creditMinor === 0) {
                throw new UnbalancedJournalException(__('filament-accounting::errors.journal_zero_line'));
            }

            if ($line->debitMinor < 0 || $line->creditMinor < 0) {
                throw new UnbalancedJournalException(__('filament-accounting::errors.journal_negative_line'));
            }
        }

        $accountIds = collect($lines)
            ->pluck('ledgerAccountId')
            ->unique()
            ->values();

        $accounts = LedgerAccount::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_active', true)
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($accounts->count() !== $accountIds->count()) {
            throw new UnbalancedJournalException(__('filament-accounting::errors.ledger_account_invalid'));
        }

        return $accounts;
    }

    private function nextSequence(LegalEntity $entity): string
    {
        $year = now()->year;
        $last = JournalEntry::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('sequence', 'like', $year.'-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('sequence');

        $next = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $matches) === 1) {
            $next = ((int) $matches[1]) + 1;
        }

        return sprintf('%d-%06d', $year, $next);
    }
}
