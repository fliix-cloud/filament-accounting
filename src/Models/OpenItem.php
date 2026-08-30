<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $document_id
 * @property int $party_id
 * @property OpenItemKind $kind
 * @property string $currency
 * @property int $original_minor
 * @property Carbon|null $due_on
 * @property bool $is_reversed
 * @property-read Document $document
 * @property-read Party|null $party
 */
class OpenItem extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_open_items';

    protected $fillable = [
        'legal_entity_id',
        'document_id',
        'party_id',
        'kind',
        'currency',
        'original_minor',
        'due_on',
        'is_reversed',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OpenItemKind::class,
            'original_minor' => 'integer',
            'due_on' => 'date',
            'is_reversed' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function remainingMinor(): int
    {
        $settled = (int) $this->settlements()
            ->where('is_reversed', false)
            ->sum('amount_minor');

        return $this->original_minor - $settled;
    }

    public function derivedPaymentStatus(): PaymentStatus
    {
        $remaining = $this->remainingMinor();
        $original = $this->original_minor;

        if ($remaining === $original) {
            return PaymentStatus::Unpaid;
        }

        if ($remaining === 0) {
            return PaymentStatus::Paid;
        }

        if (($original > 0 && $remaining < 0) || ($original < 0 && $remaining > 0)) {
            return PaymentStatus::Overpaid;
        }

        return PaymentStatus::PartiallyPaid;
    }

    public function isFullyCleared(): bool
    {
        return $this->remainingMinor() === 0;
    }
}
