<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateType;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitSequenceType;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyBankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $party_id
 * @property int $party_bank_account_id
 * @property int $creditor_profile_id
 * @property string $reference
 * @property string $reference_normalized
 * @property DirectDebitScheme $scheme
 * @property DirectDebitMandateType $mandate_type
 * @property string $debtor_name
 * @property string $debtor_iban
 * @property string|null $debtor_bic
 * @property string|null $debtor_street
 * @property string|null $debtor_building_number
 * @property string|null $debtor_postal_code
 * @property string|null $debtor_city
 * @property string|null $debtor_country
 * @property Carbon $signed_on
 * @property DirectDebitMandateStatus $status
 * @property Carbon|null $debtor_bank_confirmed_at
 * @property Carbon|null $first_used_at
 * @property Carbon|null $last_used_at
 * @property DirectDebitCreditorProfile $creditorProfile
 */
class DirectDebitMandate extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_direct_debit_mandates';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->status ??= DirectDebitMandateStatus::Active;
        });

        static::saving(function (self $model): void {
            $bankAccount = PartyBankAccount::query()
                ->with(['party.addresses'])
                ->findOrFail($model->party_bank_account_id);
            $party = $bankAccount->party;
            $address = $party->addresses->firstWhere('is_primary', true) ?? $party->addresses->first();

            $model->legal_entity_id = $bankAccount->legal_entity_id;
            $model->party_id = $bankAccount->party_id;
            $model->debtor_name = $bankAccount->holder_name ?: $party->displayLabel();
            $model->debtor_iban = $bankAccount->iban;
            $model->debtor_bic = $bankAccount->bic;
            $model->debtor_street = $address?->line1;
            $model->debtor_building_number = $address?->line2;
            $model->debtor_postal_code = $address?->postal_code;
            $model->debtor_city = $address?->city;
            $model->debtor_country = $address !== null ? $address->country_code : $party->country_code;
            $model->reference = trim((string) $model->reference);
            $model->reference_normalized = strtoupper($model->reference);
            $model->debtor_iban = SepaIdentifier::normalize((string) $model->debtor_iban);
            $model->debtor_bic = filled($model->debtor_bic) ? SepaIdentifier::normalize((string) $model->debtor_bic) : null;
            $model->debtor_country = filled($model->debtor_country) ? strtoupper((string) $model->debtor_country) : null;
        });

        static::updating(function (self $model): void {
            if ($model->getOriginal('first_used_at') === null) {
                return;
            }

            $identity = [
                'creditor_profile_id',
                'party_id',
                'party_bank_account_id',
                'reference',
                'scheme',
                'mandate_type',
                'signed_on',
            ];

            if ($model->isDirty($identity)) {
                throw new \LogicException('A used direct-debit mandate is immutable; create a successor mandate.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'scheme' => DirectDebitScheme::class,
            'mandate_type' => DirectDebitMandateType::class,
            'status' => DirectDebitMandateStatus::class,
            'signed_on' => 'date',
            'debtor_bank_confirmed_at' => 'datetime',
            'first_used_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function partyBankAccount(): BelongsTo
    {
        return $this->belongsTo(PartyBankAccount::class);
    }

    public function creditorProfile(): BelongsTo
    {
        return $this->belongsTo(DirectDebitCreditorProfile::class, 'creditor_profile_id');
    }

    public function directDebits(): HasMany
    {
        return $this->hasMany(BankDirectDebit::class, 'direct_debit_mandate_id');
    }

    public function nextSequenceType(bool $final = false): DirectDebitSequenceType
    {
        if ($this->mandate_type === DirectDebitMandateType::OneOff) {
            return DirectDebitSequenceType::OneOff;
        }

        if ($final) {
            return DirectDebitSequenceType::Final;
        }

        return $this->first_used_at instanceof Carbon
            ? DirectDebitSequenceType::Recurring
            : DirectDebitSequenceType::First;
    }

    public function canCollect(): bool
    {
        return $this->status === DirectDebitMandateStatus::Active;
    }

    public function markCollected(bool $final = false): void
    {
        $now = now();
        $this->first_used_at ??= $now;
        $this->last_used_at = $now;

        if ($final || $this->mandate_type === DirectDebitMandateType::OneOff) {
            $this->status = DirectDebitMandateStatus::Closed;
        }

        $this->save();
    }

    public function label(): string
    {
        return $this->reference.' · '.$this->debtor_name;
    }
}
