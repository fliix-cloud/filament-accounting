<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $display_name
 * @property string|null $iban
 * @property string|null $bic
 * @property string $currency
 * @property int $ledger_account_id
 * @property string $driver_key
 * @property string $external_account_id
 * @property bool $is_active
 * @property-read LedgerAccount $ledgerAccount
 */
class AccountingBankAccount extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_bank_accounts';

    protected $fillable = [
        'legal_entity_id',
        'display_name',
        'iban',
        'bic',
        'currency',
        'ledger_account_id',
        'driver_key',
        'external_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_account_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(BankImportRun::class, 'bank_account_id');
    }
}
