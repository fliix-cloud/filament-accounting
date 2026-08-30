<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $legal_entity_id
 * @property AccountRole $role
 * @property int $ledger_account_id
 */
class AccountRoleAssignment extends AccountingModel
{
    use BelongsToLegalEntity;

    protected $table = 'accounting_account_role_assignments';

    protected $fillable = [
        'legal_entity_id',
        'role',
        'ledger_account_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
