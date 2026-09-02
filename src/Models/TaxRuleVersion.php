<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\TaxRulePeriodOverlapException;
use FilamentAccounting\Exceptions\TaxRuleVersionImmutableException;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tax_code_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property int $rate_bp
 * @property bool $recoverable
 * @property string|null $category
 * @property string|null $reason
 * @property array<string, mixed>|null $export_mapping
 */
class TaxRuleVersion extends AccountingModel
{
    use HasUuid;

    protected $table = 'accounting_tax_rule_versions';

    protected $fillable = [
        'tax_code_id',
        'valid_from',
        'valid_to',
        'rate_bp',
        'recoverable',
        'category',
        'reason',
        'export_mapping',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            $from = Carbon::parse($version->valid_from)->startOfDay();
            $to = $version->valid_to !== null ? Carbon::parse($version->valid_to)->startOfDay() : null;

            if ($to !== null && $to->lt($from)) {
                throw new TaxRulePeriodOverlapException(__('filament-accounting::errors.tax_rule_invalid_period'));
            }

            if ($version->exists && $version->isDirty() && $version->isReferenced()) {
                throw new TaxRuleVersionImmutableException(__('filament-accounting::errors.tax_rule_version_immutable'));
            }

            $overlap = self::query()
                ->where('tax_code_id', $version->tax_code_id)
                ->when($version->exists, fn ($query) => $query->whereKeyNot($version->getKey()))
                ->whereDate('valid_from', '<=', ($to ?? Carbon::parse('9999-12-31'))->toDateString())
                ->where(function ($query) use ($from): void {
                    $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString());
                })
                ->exists();

            if ($overlap) {
                throw new TaxRulePeriodOverlapException(__('filament-accounting::errors.tax_rule_period_overlap'));
            }
        });

        static::deleting(function (self $version): void {
            if ($version->isReferenced()) {
                throw new TaxRuleVersionImmutableException(__('filament-accounting::errors.tax_rule_version_immutable'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'rate_bp' => 'integer',
            'recoverable' => 'boolean',
            'export_mapping' => 'array',
        ];
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function isReferenced(): bool
    {
        return DocumentLine::query()->where('tax_rule_version_id', $this->getKey())->exists()
            || JournalLine::query()->where('tax_rule_version_id', $this->getKey())->exists();
    }
}
