<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitSequenceType;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $bank_connection_id
 * @property int $legal_entity_id
 * @property int $accounting_bank_account_id
 * @property int|null $creditor_profile_id
 * @property int|null $direct_debit_mandate_id
 * @property string $idempotency_key
 * @property string|null $sepa_message_id
 * @property string|null $payment_information_id
 * @property string $creditor_name
 * @property string|null $creditor_identifier
 * @property string|null $creditor_street
 * @property string|null $creditor_building_number
 * @property string|null $creditor_postal_code
 * @property string|null $creditor_city
 * @property string|null $creditor_country
 * @property string $debtor_name
 * @property string $debtor_iban
 * @property string|null $debtor_bic
 * @property string|null $debtor_street
 * @property string|null $debtor_building_number
 * @property string|null $debtor_postal_code
 * @property string|null $debtor_city
 * @property string|null $debtor_country
 * @property int $amount_minor
 * @property-read string $amount
 * @property string $currency
 * @property string|null $purpose
 * @property string $mandate_id
 * @property Carbon|null $mandate_signed_on
 * @property DirectDebitSequenceType $sequence_type
 * @property DirectDebitScheme $scheme
 * @property Carbon|null $requested_collection_date
 * @property string|null $end_to_end_id
 * @property PaymentStatus $status
 * @property string|null $bank_status_text
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $submitted_at
 * @property DirectDebitCreditorProfile|null $creditorProfile
 * @property DirectDebitMandate|null $mandate
 */
class BankDirectDebit extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_bank_direct_debits';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $account = AccountingBankAccount::query()->findOrFail($model->accounting_bank_account_id);
            $model->legal_entity_id = $account->legal_entity_id;
            $model->bank_connection_id = $account->bank_connection_id;
            $model->uuid ??= (string) Str::uuid();
            $model->idempotency_key ??= (string) Str::uuid();
            $model->sepa_message_id ??= SepaIdentifier::externalId($model->uuid);
            $model->payment_information_id ??= SepaIdentifier::externalId($model->idempotency_key);
            $model->end_to_end_id ??= 'NOTPROVIDED';
            $model->status ??= PaymentStatus::Draft;
            $model->currency = 'EUR';
        });

        static::updated(function (self $model): void {
            if (! $model->wasChanged('status') || $model->status !== PaymentStatus::Submitted) {
                return;
            }

            $mandate = $model->mandate;
            if ($mandate instanceof DirectDebitMandate) {
                $mandate->markCollected($model->sequence_type === DirectDebitSequenceType::Final);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'mandate_signed_on' => 'date',
            'requested_collection_date' => 'date',
            'sequence_type' => DirectDebitSequenceType::class,
            'scheme' => DirectDebitScheme::class,
            'status' => PaymentStatus::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingBankAccount::class, 'accounting_bank_account_id');
    }

    public function creditorProfile(): BelongsTo
    {
        return $this->belongsTo(DirectDebitCreditorProfile::class, 'creditor_profile_id');
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(DirectDebitMandate::class, 'direct_debit_mandate_id');
    }

    public function initiatedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAmountAttribute(): string
    {
        return ExactMoney::ofMinor((int) $this->amount_minor, $this->currency ?: 'EUR')->decimalString();
    }

    public function setAmountAttribute(mixed $value): void
    {
        $this->attributes['amount_minor'] = ExactMoney::ofString((string) $value, $this->currency ?: 'EUR')->minorAmount;
    }

    /** Snapshot alias used by the XML generator; do not confuse it with the mandate relation FK. */
    public function getMandateIdSnapshotAttribute(): ?string
    {
        return $this->getAttribute('mandate_id');
    }
}
