<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Contracts\AccountingExporter;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;

final class GenericJournalCsvExporter implements AccountingExporter
{
    public function key(): string
    {
        return 'generic-journal-csv';
    }

    public function export(LegalEntity $entity, string $from, string $to, array $options = []): string
    {
        $entries = JournalEntry::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('status', JournalStatus::Posted)
            ->whereDate('posted_on', '>=', $from)
            ->whereDate('posted_on', '<=', $to)
            ->with(['lines.ledgerAccount', 'period'])
            ->orderBy('posted_on')
            ->orderBy('sequence')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'sequence',
            'posted_on',
            'account_code',
            'account_name',
            'debit_minor',
            'credit_minor',
            'currency',
            'tax_code',
            'description',
            'source_type',
            'source_id',
            'period',
        ]);

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                fputcsv($handle, [
                    $entry->sequence,
                    $entry->posted_on?->toDateString(),
                    $line->ledgerAccount?->code,
                    $line->ledgerAccount?->name,
                    $line->debit_minor,
                    $line->credit_minor,
                    $line->currency,
                    $line->tax_code,
                    $line->description,
                    $entry->source_type,
                    $entry->source_id,
                    $entry->period?->fiscal_year.'-'.$entry->period?->period_number,
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
