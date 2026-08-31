<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\SplitPurpose;
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

    public function amountMatches(): ?bool
    {
        $meta = $this->match_meta;
        if (is_array($meta) && array_key_exists('amount_match', $meta)) {
            return (bool) $meta['amount_match'];
        }

        if (($meta['mode'] ?? null) !== 'direct') {
            return null;
        }

        $this->loadMissing(['splits.openItem', 'statementLine']);
        if ($this->splits->count() !== 1) {
            return null;
        }

        $split = $this->splits->first();
        if (! $split instanceof ReconciliationSplit
            || $split->purpose !== SplitPurpose::SettleOpenItem
            || ! $split->openItem instanceof OpenItem
            || ! $this->statementLine instanceof BankStatementLine) {
            return null;
        }

        $remainingBefore = abs($split->openItem->remainingMinor()) + abs((int) $split->amount_minor);

        return abs((int) $this->statementLine->amount_minor) === $remainingBefore;
    }
}
