<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $party_id
 * @property string|null $holder_name
 * @property string $iban
 * @property string|null $bic
 * @property bool $is_primary
 * @property-read Party $party
 * @property-read LegalEntity $legalEntity
 */
class PartyBankAccount extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_party_bank_accounts';

    protected $fillable = [
        'legal_entity_id',
        'party_id',
        'holder_name',
        'iban',
        'bic',
        'is_primary',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if (! $model->legal_entity_id && $model->party_id) {
                $model->legal_entity_id = (int) Party::query()->whereKey($model->party_id)->value('legal_entity_id');
            }

            $model->iban = Sepa::normalizeIban((string) $model->iban);
            $model->bic = filled($model->bic) ? strtoupper(preg_replace('/\s+/', '', (string) $model->bic) ?? '') : null;
        });

        static::saved(function (self $model): void {
            if ($model->is_primary) {
                self::query()
                    ->where('party_id', $model->party_id)
                    ->whereKeyNot($model->getKey())
                    ->update(['is_primary' => false]);
            }
        });
    }

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

    public function label(): string
    {
        return $this->iban;
    }
}
