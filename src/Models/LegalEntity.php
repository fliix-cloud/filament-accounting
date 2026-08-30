<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\LegalEntityState;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $legal_name
 * @property string|null $trading_name
 * @property string $country_code
 * @property string $base_currency
 * @property string $locale
 * @property string $timezone
 * @property int $fiscal_year_start_month
 * @property string $accounting_basis
 * @property string|null $vat_method
 * @property string $compliance_profile_key
 * @property LegalEntityState $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Party> $parties
 * @property-read Collection<int, LedgerAccount> $ledgerAccounts
 * @property-read Collection<int, AccountingPeriod> $periods
 * @property-read Collection<int, Document> $documents
 */
class LegalEntity extends AccountingModel
{
    use HasUuid;

    protected $table = 'accounting_legal_entities';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'legal_name',
        'trading_name',
        'country_code',
        'base_currency',
        'locale',
        'timezone',
        'fiscal_year_start_month',
        'accounting_basis',
        'vat_method',
        'compliance_profile_key',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
            'state' => LegalEntityState::class,
        ];
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
