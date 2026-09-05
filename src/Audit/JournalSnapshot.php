<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Model;

/** Versioned, persisted journal values; never dereferences mutable master data. */
final class JournalSnapshot
{
    public const VERSION = 1;

    public function __construct(private readonly CanonicalJson $json) {}

    /** @return array<string, mixed> */
    public function capture(JournalEntry $entry): array
    {
        return [
            'schema_version' => self::VERSION,
            'entry' => $this->attributes($entry, [
                'id', 'uuid', 'legal_entity_id', 'sequence', 'period_id',
                'posted_on', 'status', 'source_type', 'source_id', 'description',
                'currency', 'base_currency', 'exchange_rate', 'posting_rule_version_id',
                'reverses_id', 'idempotency_key', 'posted_by_type', 'posted_by_id',
                'posted_at', 'created_at', 'updated_at',
            ], ['id', 'legal_entity_id', 'period_id', 'posting_rule_version_id', 'reverses_id']) + [
                'period_snapshot' => $entry->period_snapshot,
            ],
            'lines' => $entry->lines->sortBy([
                ['position', 'asc'], ['id', 'asc'],
            ])->map(fn (JournalLine $line): array => $this->attributes($line, [
                'id', 'journal_entry_id', 'ledger_account_id', 'position',
                'debit_minor', 'credit_minor', 'currency', 'base_debit_minor',
                'base_credit_minor', 'description', 'tax_code', 'tax_rule_version_id',
                'created_at', 'updated_at',
            ], [
                'id', 'journal_entry_id', 'ledger_account_id', 'position', 'debit_minor',
                'credit_minor', 'base_debit_minor', 'base_credit_minor', 'tax_rule_version_id',
            ]) + ['account_snapshot' => $line->account_snapshot])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', $this->json->encode($snapshot));
    }

    /**
     * @param list<string> $fields
     * @param list<string> $integers
     * @return array<string, mixed>
     */
    private function attributes(Model $model, array $fields, array $integers): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = $model->getRawOriginal($field);
            $values[$field] = $value === null ? null : (in_array($field, $integers, true) ? (int) $value : (string) $value);
        }

        return $values;
    }
}
