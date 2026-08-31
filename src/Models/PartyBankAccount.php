<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\PartyMandateScheme;
use FilamentAccounting\Enums\PartyMandateStatus;
use FilamentAccounting\Enums\PartyMandateType;
use FilamentAccounting\Events\PartyBankAccountChanged;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $party_id
 * @property string|null $holder_name
 * @property string $iban
 * @property string|null $bic
 * @property bool $is_primary
 * @property string|null $mandate_reference
 * @property string|null $mandate_reference_normalized
 * @property Carbon|null $mandate_signed_on
 * @property PartyMandateScheme|null $mandate_scheme
 * @property PartyMandateType|null $mandate_type
 * @property PartyMandateStatus|null $mandate_status
 * @property string|null $external_mandate_id
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
        'mandate_reference',
        'mandate_reference_normalized',
        'mandate_signed_on',
        'mandate_scheme',
        'mandate_type',
        'mandate_status',
        'external_mandate_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if (! $model->legal_entity_id && $model->party_id) {
                $model->legal_entity_id = (int) Party::query()->whereKey($model->party_id)->value('legal_entity_id');
            }

            $model->iban = Sepa::normalizeIban((string) $model->iban);
            $model->bic = filled($model->bic) ? strtoupper(preg_replace('/\s+/', '', (string) $model->bic) ?? '') : null;
            $model->mandate_reference = filled($model->mandate_reference) ? trim((string) $model->mandate_reference) : null;
            $model->mandate_reference_normalized = Sepa::normalizeMandateReference($model->mandate_reference);

            if ($model->mandate_reference === null) {
                $model->mandate_scheme = null;
                $model->mandate_type = null;
                $model->mandate_status = null;
                $model->mandate_signed_on = null;
            } else {
                $model->mandate_scheme ??= PartyMandateScheme::Core;
                $model->mandate_type ??= PartyMandateType::Recurring;
                $model->mandate_status ??= PartyMandateStatus::Active;
                $model->mandate_signed_on ??= now();
            }
        });

        static::saved(function (self $model): void {
            if ($model->is_primary) {
                self::query()
                    ->where('party_id', $model->party_id)
                    ->whereKeyNot($model->getKey())
                    ->update(['is_primary' => false]);
            }

            PartyBankAccountChanged::dispatch($model->fresh() ?? $model);
        });
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'mandate_signed_on' => 'date',
            'mandate_scheme' => PartyMandateScheme::class,
            'mandate_type' => PartyMandateType::class,
            'mandate_status' => PartyMandateStatus::class,
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function hasMandate(): bool
    {
        return filled($this->mandate_reference);
    }

    public function label(): string
    {
        $iban = $this->iban;
        if ($this->mandate_reference) {
            return $this->mandate_reference.' · '.$iban;
        }

        return $iban;
    }
}
