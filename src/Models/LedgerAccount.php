<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\NormalBalance;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property string|null $currency
 * @property int|null $parent_id
 * @property bool $is_active
 */
class LedgerAccount extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_ledger_accounts';

    protected $fillable = [
        'legal_entity_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'currency',
        'parent_id',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(AccountRoleAssignment::class);
    }

    public function label(): string
    {
        return $this->code.' '.$this->name;
    }
}
