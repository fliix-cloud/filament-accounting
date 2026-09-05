<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
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
 * @property string|null $sequence
 * @property int $period_id
 * @property Carbon $posted_on
 * @property JournalStatus $status
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $description
 * @property string $currency
 * @property string $base_currency
 * @property string|null $exchange_rate
 * @property int|null $posting_rule_version_id
 * @property int|null $reverses_id
 * @property string|null $idempotency_key
 * @property string|null $posted_by_type
 * @property string|null $posted_by_id
 * @property Carbon|null $posted_at
 * @property-read Collection<int, JournalLine> $lines
 * @property-read AccountingPeriod|null $period
 */
class JournalEntry extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_journal_entries';

    protected $fillable = [
        'legal_entity_id',
        'sequence',
        'period_id',
        'posted_on',
        'status',
        'source_type',
        'source_id',
        'description',
        'currency',
        'base_currency',
        'exchange_rate',
        'posting_rule_version_id',
        'reverses_id',
        'idempotency_key',
        'posted_by_type',
        'posted_by_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_on' => 'date',
            'status' => JournalStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            if (self::originalIsPosted($entry) || self::query()->whereKey($entry->getKey())->where('status', JournalStatus::Posted)->exists()) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.journal_immutable')
                );
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->status === JournalStatus::Posted || self::originalIsPosted($entry)
                || self::query()->whereKey($entry->getKey())->where('status', JournalStatus::Posted)->exists()) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.journal_immutable')
                );
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('position');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_id');
    }

    public function postingRuleVersion(): BelongsTo
    {
        return $this->belongsTo(PostingRuleVersion::class);
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
    }

    private static function originalIsPosted(self $entry): bool
    {
        $original = $entry->getOriginal('status');

        if ($original instanceof JournalStatus) {
            return $original === JournalStatus::Posted;
        }

        return $original === JournalStatus::Posted->value;
    }
}
