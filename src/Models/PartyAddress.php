<?php

namespace FilamentAccounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $party_id
 * @property string|null $line1
 * @property string|null $city
 * @property string|null $country_code
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
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
