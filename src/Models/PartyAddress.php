<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\PartyAddressRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $party_id
 * @property string|null $line1
 * @property string|null $line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country_code
 * @property PartyAddressRole|null $address_role
 * @property bool $is_primary
 */
class PartyAddress extends AccountingModel
{
    protected $table = 'accounting_party_addresses';

    protected $fillable = [
        'party_id',
        'line1',
        'line2',
        'postal_code',
        'city',
        'region',
        'country_code',
        'address_role',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'address_role' => PartyAddressRole::class,
            'is_primary' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
