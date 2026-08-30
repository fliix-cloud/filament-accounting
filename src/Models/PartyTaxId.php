<?php

namespace FilamentAccounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $party_id
 * @property string $type
 * @property string $number
 * @property string|null $country_code
 */
class PartyTaxId extends AccountingModel
{
    protected $table = 'accounting_party_tax_ids';

    protected $fillable = [
        'party_id',
        'type',
        'number',
        'country_code',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
