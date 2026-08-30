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
 * @property string $label
 * @property string|null $explanation
 * @property string $compliance_profile_key
 * @property bool $is_active
 */
class PostingRule extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_posting_rules';

    protected $fillable = [
        'legal_entity_id',
        'code',
        'label',
        'explanation',
        'compliance_profile_key',
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
        return $this->hasMany(PostingRuleVersion::class);
    }

    public function versionOn(string $date): ?PostingRuleVersion
    {
        $version = $this->versions()
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('version')
            ->first();

        return $version instanceof PostingRuleVersion ? $version : null;
    }
}
