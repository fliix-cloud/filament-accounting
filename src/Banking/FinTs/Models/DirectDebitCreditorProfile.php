<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $name
 * @property string $creditor_identifier
 * @property string $creditor_identifier_normalized
 * @property string|null $street
 * @property string|null $building_number
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property bool $is_default
 */
class DirectDebitCreditorProfile extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_direct_debit_creditor_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->legal_entity_id ??= app(LegalEntityScope::class)->require()->getKey();
            $model->uuid ??= (string) Str::uuid();
        });

        static::saving(function (self $model): void {
            $entity = LegalEntity::query()->find($model->legal_entity_id);
            if ($entity instanceof LegalEntity) {
                $model->name = $entity->legal_name;
                $model->street = $entity->address_line1;
                $model->building_number = null;
                $model->postal_code = $entity->postal_code;
                $model->city = $entity->city;
                $model->country = $entity->country_code;
            }
            $model->creditor_identifier = SepaIdentifier::normalize((string) $model->creditor_identifier);
            $model->creditor_identifier_normalized = $model->creditor_identifier;
            $model->country = filled($model->country) ? strtoupper((string) $model->country) : null;
        });

        static::saved(function (self $model): void {
            if (! $model->is_default) {
                return;
            }

            self::query()
                ->whereKeyNot($model->getKey())
                ->where('legal_entity_id', $model->legal_entity_id)
                ->update(['is_default' => false]);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function mandates(): HasMany
    {
        return $this->hasMany(DirectDebitMandate::class, 'creditor_profile_id');
    }

    public function directDebits(): HasMany
    {
        return $this->hasMany(BankDirectDebit::class, 'creditor_profile_id');
    }

    public function label(): string
    {
        return $this->name.' · '.$this->creditor_identifier;
    }
}
