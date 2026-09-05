<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property DocumentType $type
 * @property DocumentDirection $direction
 * @property string|null $number
 * @property string|null $supplier_invoice_number
 * @property DocumentStatus $document_status
 * @property PostingStatus $posting_status
 * @property int|null $party_id
 * @property array<string, mixed>|null $party_snapshot
 * @property array<string, mixed>|null $legal_entity_snapshot
 * @property Carbon|null $issue_date
 * @property Carbon|null $receipt_date
 * @property Carbon|null $supply_date
 * @property Carbon|null $due_date
 * @property int|null $payment_terms_days
 * @property string $currency
 * @property string|null $exchange_rate
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $gross_minor
 * @property array<string, mixed>|null $e_invoice_meta
 * @property int|null $corrected_document_id
 * @property string|null $idempotency_key
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property string|null $issued_by_type
 * @property string|null $issued_by_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $posted_at
 * @property-read Collection<int, DocumentLine> $lines
 * @property-read Collection<int, Attachment> $attachments
 * @property-read OpenItem|null $openItem
 * @property-read Party|null $party
 * @property-read Collection<int, Settlement> $settlements
 */
class Document extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_documents';

    /**
     * @var list<string>
     */
    public const COMMERCIAL_FIELDS = [
        'type',
        'direction',
        'number',
        'supplier_invoice_number',
        'party_id',
        'party_snapshot',
        'legal_entity_snapshot',
        'issue_date',
        'receipt_date',
        'supply_date',
        'due_date',
        'payment_terms_days',
        'currency',
        'exchange_rate',
        'net_minor',
        'tax_minor',
        'gross_minor',
        'e_invoice_meta',
        'corrected_document_id',
    ];

    protected $fillable = [
        'legal_entity_id',
        'type',
        'direction',
        'number',
        'supplier_invoice_number',
        'document_status',
        'posting_status',
        'party_id',
        'party_snapshot',
        'legal_entity_snapshot',
        'issue_date',
        'receipt_date',
        'supply_date',
        'due_date',
        'payment_terms_days',
        'currency',
        'exchange_rate',
        'net_minor',
        'tax_minor',
        'gross_minor',
        'e_invoice_meta',
        'corrected_document_id',
        'idempotency_key',
        'created_by_type',
        'created_by_id',
        'issued_by_type',
        'issued_by_id',
        'issued_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'direction' => DocumentDirection::class,
            'document_status' => DocumentStatus::class,
            'posting_status' => PostingStatus::class,
            'party_snapshot' => 'array',
            'legal_entity_snapshot' => 'array',
            'issue_date' => 'date',
            'receipt_date' => 'date',
            'supply_date' => 'date',
            'due_date' => 'date',
            'payment_terms_days' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
            'e_invoice_meta' => 'array',
            'issued_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $stored = self::query()->findOrFail($document->getKey());
            $protected = ['legal_entity_id', 'uuid', 'type', 'direction', 'created_by_type', 'created_by_id'];

            if ($stored->document_status !== DocumentStatus::Draft || $stored->isPosted()) {
                $protected = array_merge($protected, self::COMMERCIAL_FIELDS, [
                    'document_status', 'issued_at', 'issued_by_type', 'issued_by_id',
                ]);
            } elseif ($document->isDirty('document_status')) {
                $allowed = match ($stored->type) {
                    DocumentType::SalesInvoice => [DocumentStatus::Issued],
                    DocumentType::PurchaseInvoice => [DocumentStatus::Received, DocumentStatus::Discarded],
                    default => [],
                };
                if (! in_array($document->document_status, $allowed, true)) {
                    throw new PostedRecordImmutableException(__('filament-accounting::errors.document_immutable'));
                }
            }

            if ($stored->posting_status !== PostingStatus::Unposted) {
                $protected = array_merge($protected, ['posting_status', 'posted_at']);
            }

            foreach ($protected as $field) {
                if ($document->isDirty($field)) {
                    throw new PostedRecordImmutableException(
                        __('filament-accounting::errors.document_immutable')
                    );
                }
            }
        });

        static::deleting(function (self $document): void {
            $stored = self::query()->findOrFail($document->getKey());
            if ($stored->type === DocumentType::PurchaseInvoice || $stored->isIssuedOrReceived() || $stored->isPosted()) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.document_immutable')
                );
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }

    public function openItem(): HasOne
    {
        return $this->hasOne(OpenItem::class);
    }

    public function settlements(): HasManyThrough
    {
        return $this->hasManyThrough(
            Settlement::class,
            OpenItem::class,
            'document_id',
            'open_item_id',
        )->where('accounting_settlements.is_reversed', false);
    }

    public function correctedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_document_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isIssuedOrReceived(): bool
    {
        $original = $this->getOriginal('document_status');
        $status = $original instanceof DocumentStatus ? $original : DocumentStatus::tryFrom((string) $original);

        return in_array($status, [DocumentStatus::Issued, DocumentStatus::Received, DocumentStatus::Corrected, DocumentStatus::Cancelled, DocumentStatus::Discarded], true)
            || in_array($this->document_status, [DocumentStatus::Issued, DocumentStatus::Received, DocumentStatus::Corrected, DocumentStatus::Cancelled, DocumentStatus::Discarded], true);
    }

    public function isPosted(): bool
    {
        return $this->posting_status === PostingStatus::Posted;
    }

    public function paymentStatus(): PaymentStatus
    {
        $item = $this->openItem;

        if (! $item instanceof OpenItem) {
            return PaymentStatus::Unpaid;
        }

        return $item->derivedPaymentStatus();
    }
}
