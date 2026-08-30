<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $code
 * @property string $name
 * @property string|null $direction
 * @property bool $is_active
 */
class TaxCode extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_tax_codes';

    protected $fillable = [
        'legal_entity_id',
        'code',
        'name',
        'direction',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaxRuleVersion::class);
    }

    public function versionOn(string $date): ?TaxRuleVersion
    {
        $version = $this->versions()
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->first();

        return $version instanceof TaxRuleVersion ? $version : null;
    }
}
