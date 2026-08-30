<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $fiscal_year
 * @property int $period_number
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property PeriodState $state
 * @property Carbon|null $closed_at
 * @property string|null $closed_by_type
 * @property string|null $closed_by_id
 * @property Carbon|null $reopened_at
 * @property string|null $reopened_by_type
 * @property string|null $reopened_by_id
 * @property string|null $reopen_reason
 * @property-read LegalEntity $legalEntity
 */
class AccountingPeriod extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_periods';

    protected $fillable = [
        'legal_entity_id',
        'fiscal_year',
        'period_number',
        'starts_on',
        'ends_on',
        'state',
        'closed_at',
        'closed_by_type',
        'closed_by_id',
        'reopened_at',
        'reopened_by_type',
        'reopened_by_id',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_number' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'state' => PeriodState::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'period_id');
    }

    public function isHardClosed(): bool
    {
        return $this->state === PeriodState::HardClosed;
    }
}
