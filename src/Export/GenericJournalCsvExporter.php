<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Audit\AuditAnchorVerifier;
use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Contracts\AccountingExporter;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Exceptions\JournalIntegrityException;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;

final class GenericJournalCsvExporter implements AccountingExporter
{
    public function __construct(
        private readonly JournalIntegrityVerifier $journals,
        private readonly AuditChainVerifier $chain,
        private readonly AuditAnchorVerifier $anchors,
    ) {}

    public function key(): string
    {
        return 'generic-journal-csv';
    }

    public function export(LegalEntity $entity, string $from, string $to, array $options = []): string
    {
        return $entity->getConnection()->transaction(function () use ($entity, $from, $to): string {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $ledger = $this->journals->verify((int) $entity->getKey());
            $chain = $this->chain->verify((int) $entity->getKey());
            $anchors = $this->anchors->verify($entity, $chain);
            if ($ledger['issues'] !== [] || ! $chain->isValid() || ! $anchors->isValid()) {
                throw new JournalIntegrityException(__('filament-accounting::errors.journal_integrity_failed'));
            }

            // Export the records just verified, never a second read or live master data.
            $entries = $ledger['entries']->filter(fn (JournalEntry $entry): bool => $entry->getRawOriginal('status') === JournalStatus::Posted->value
                && $entry->getRawOriginal('posted_on') >= $from
                && $entry->getRawOriginal('posted_on') <= $to
            )->sortBy([['posted_on', 'asc'], ['sequence', 'asc']]);

            $handle = fopen('php://temp', 'r+');
            try {
                fputcsv($handle, [
                    'sequence', 'posted_on', 'account_code', 'account_name',
                    'debit_minor', 'credit_minor', 'currency', 'tax_code',
                    'description', 'source_type', 'source_id', 'period',
                ], escape: '');

                foreach ($entries as $entry) {
                    foreach ($entry->lines->sortBy([['position', 'asc'], ['id', 'asc']]) as $line) {
                        fputcsv($handle, [
                            $entry->sequence,
                            $entry->posted_on?->toDateString(),
                            $line->account_snapshot['code'],
                            $line->account_snapshot['name'],
                            $line->debit_minor,
                            $line->credit_minor,
                            $line->currency,
                            $line->tax_code,
                            $line->description,
                            $entry->source_type,
                            $entry->source_id,
                            $entry->period_snapshot['fiscal_year'].'-'.$entry->period_snapshot['period_number'],
                        ], escape: '');
                    }
                }

                rewind($handle);

                return stream_get_contents($handle) ?: '';
            } finally {
                fclose($handle);
            }
        });
    }
}
