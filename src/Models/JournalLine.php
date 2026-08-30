<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $ledger_account_id
 * @property int $position
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string $currency
 * @property int $base_debit_minor
 * @property int $base_credit_minor
 * @property string|null $description
 * @property string|null $tax_code
 * @property int|null $tax_rule_version_id
 * @property-read LedgerAccount|null $ledgerAccount
 * @property-read JournalEntry $journalEntry
 */
class JournalLine extends AccountingModel
{
    protected $table = 'accounting_journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'position',
        'debit_minor',
        'credit_minor',
        'currency',
        'base_debit_minor',
        'base_credit_minor',
        'description',
        'tax_code',
        'tax_rule_version_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'base_debit_minor' => 'integer',
            'base_credit_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if (self::parentIsPosted($line)) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.journal_line_immutable')
                );
            }
        });

        static::deleting(function (self $line): void {
            if (self::parentIsPosted($line)) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.journal_line_immutable')
                );
            }
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    private static function parentIsPosted(self $line): bool
    {
        if (! $line->journal_entry_id) {
            return false;
        }

        $entry = $line->relationLoaded('journalEntry')
            ? $line->journalEntry
            : JournalEntry::query()->find($line->journal_entry_id);

        return $entry instanceof JournalEntry && $entry->status === JournalStatus::Posted;
    }
}
