<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\DerivedReconciliationBadge;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $bank_account_id
 * @property string $driver_key
 * @property string $external_id
 * @property string|null $source_account_external_id
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon|null $booking_date
 * @property Carbon|null $value_date
 * @property StatementLineStatus $source_status
 * @property string|null $counterparty_name
 * @property string|null $counterparty_iban
 * @property string|null $counterparty_account
 * @property string|null $purpose
 * @property string|null $end_to_end_id
 * @property string|null $payment_reference
 * @property array<string, mixed>|null $source_payload
 * @property string|null $source_hash
 * @property Carbon|null $first_imported_at
 * @property Carbon|null $last_imported_at
 * @property bool $needs_review
 * @property array<string, mixed>|null $review_reason
 * @property-read AccountingBankAccount $bankAccount
 * @property-read Collection<int, Reconciliation> $reconciliations
 */
class BankStatementLine extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_bank_statement_lines';

    protected $fillable = [
        'legal_entity_id',
        'bank_account_id',
        'driver_key',
        'external_id',
        'source_account_external_id',
        'amount_minor',
        'currency',
        'booking_date',
        'value_date',
        'source_status',
        'counterparty_name',
        'counterparty_iban',
        'counterparty_account',
        'purpose',
        'end_to_end_id',
        'payment_reference',
        'source_payload',
        'source_hash',
        'source_created_at',
        'source_updated_at',
        'first_imported_at',
        'last_imported_at',
        'needs_review',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'booking_date' => 'date',
            'value_date' => 'date',
            'source_status' => StatementLineStatus::class,
            'source_payload' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'first_imported_at' => 'datetime',
            'last_imported_at' => 'datetime',
            'needs_review' => 'boolean',
            'review_reason' => 'array',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingBankAccount::class, 'bank_account_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class, 'statement_line_id');
    }

    public function activePostedReconciliation(): ?Reconciliation
    {
        return $this->reconciliations
            ->first(fn (Reconciliation $reconciliation): bool => $reconciliation->status === ReconciliationStatus::Posted);
    }

    public function assignedAmountMatches(): ?bool
    {
        return $this->activePostedReconciliation()?->amountMatches();
    }

    public function derivedBadge(): DerivedReconciliationBadge
    {
        if ($this->needs_review) {
            return DerivedReconciliationBadge::Review;
        }

        $posted = $this->activePostedReconciliation();

        if ($posted instanceof Reconciliation) {
            return DerivedReconciliationBadge::Assigned;
        }

        $draft = $this->reconciliations->first(
            fn (Reconciliation $reconciliation): bool => in_array($reconciliation->status, [
                ReconciliationStatus::Draft,
                ReconciliationStatus::Ready,
            ], true)
        );

        if ($draft instanceof Reconciliation && $draft->splits->isNotEmpty()) {
            return DerivedReconciliationBadge::Partial;
        }

        return DerivedReconciliationBadge::Unassigned;
    }

    public function isIncoming(): bool
    {
        return $this->amount_minor > 0;
    }
}
