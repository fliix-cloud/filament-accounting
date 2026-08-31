<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\PartyKind;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property PartyKind $kind
 * @property bool $is_customer
 * @property bool $is_supplier
 * @property string $legal_name
 * @property string|null $display_name
 * @property string|null $country_code
 * @property string|null $email
 * @property string|null $phone
 * @property int $payment_terms_days
 * @property string|null $default_currency
 * @property string|null $external_reference
 * @property bool $is_active
 * @property-read Collection<int, PartyTaxId> $taxIds
 * @property-read Collection<int, PartyBankAccount> $bankAccounts
 * @property-read LegalEntity $legalEntity
 */
class Party extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_parties';

    protected $fillable = [
        'legal_entity_id',
        'kind',
        'is_customer',
        'is_supplier',
        'legal_name',
        'display_name',
        'country_code',
        'email',
        'phone',
        'payment_terms_days',
        'default_currency',
        'external_reference',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PartyKind::class,
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddress::class);
    }

    public function taxIds(): HasMany
    {
        return $this->hasMany(PartyTaxId::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function openItems(): HasMany
    {
        return $this->hasMany(OpenItem::class);
    }

    public function displayLabel(): string
    {
        return $this->display_name ?: $this->legal_name;
    }

    public function snapshot(): array
    {
        return [
            'uuid' => $this->uuid,
            'legal_name' => $this->legal_name,
            'display_name' => $this->display_name,
            'country_code' => $this->country_code,
            'email' => $this->email,
            'vat_ids' => $this->taxIds->map(fn (PartyTaxId $id): array => [
                'type' => $id->type,
                'number' => $id->number,
                'country_code' => $id->country_code,
            ])->all(),
        ];
    }
}
