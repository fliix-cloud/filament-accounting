<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
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
 * @property string $idempotency_key
 * @property string $recipient_name
 * @property string $recipient_iban
 * @property string|null $recipient_bic
 * @property int $amount_minor
 * @property-read string $amount
 * @property string $currency
 * @property string|null $purpose
 * @property Carbon|null $requested_execution_date
 * @property string|null $end_to_end_id
 * @property TransferType $type
 * @property PaymentStatus $status
 * @property string|null $bank_status_text
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $submitted_at
 * @property AccountingBankAccount|null $account
 */
class BankTransfer extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_bank_transfers';

    protected $guarded = [
        'id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $account = AccountingBankAccount::query()->findOrFail($model->accounting_bank_account_id);
            $model->legal_entity_id = $account->legal_entity_id;
            $model->bank_connection_id = $account->bank_connection_id;
            $model->uuid ??= (string) Str::uuid();
            $model->idempotency_key ??= (string) Str::uuid();
            $model->status ??= PaymentStatus::Draft;
            $model->currency ??= 'EUR';
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
            'requested_execution_date' => 'date',
            'type' => TransferType::class,
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

    public function maskedRecipientIban(): string
    {
        $iban = (string) $this->recipient_iban;

        if (strlen($iban) < 8) {
            return $iban;
        }

        return substr($iban, 0, 4).str_repeat('*', max(strlen($iban) - 8, 0)).substr($iban, -4);
    }
}
