<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $statement_line_id
 * @property ReconciliationStatus $status
 * @property int|null $journal_entry_id
 * @property int $version
 * @property int|null $reverses_id
 * @property string|null $idempotency_key
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property Carbon|null $finalized_at
 * @property string|null $reason
 * @property array<string, mixed>|null $match_meta
 * @property-read Collection<int, ReconciliationSplit> $splits
 * @property-read BankStatementLine $statementLine
 * @property-read LegalEntity $legalEntity
 * @property-read JournalEntry|null $journalEntry
 */
class Reconciliation extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_reconciliations';

    protected $fillable = [
        'legal_entity_id',
        'statement_line_id',
        'status',
        'journal_entry_id',
        'version',
        'reverses_id',
        'idempotency_key',
        'actor_type',
        'actor_id',
        'finalized_at',
        'reason',
        'match_meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'version' => 'integer',
            'finalized_at' => 'datetime',
            'match_meta' => 'array',
        ];
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ReconciliationSplit::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function splitSumMinor(): int
    {
        return (int) $this->splits()->sum('amount_minor');
    }
}
