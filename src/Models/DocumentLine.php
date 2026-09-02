<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property int $unit_price_minor
 * @property string|null $discount
 * @property int $net_minor
 * @property string|null $tax_code
 * @property int|null $tax_rule_version_id
 * @property int $tax_rate_bp
 * @property string|null $tax_category
 * @property string|null $tax_reason
 * @property bool|null $tax_recoverable
 * @property array<string, mixed>|null $tax_export_mapping
 * @property int $tax_minor
 * @property int $gross_minor
 * @property string|null $account_role
 * @property int|null $ledger_account_id
 * @property int|null $catalog_item_id
 * @property string|null $classification_code
 * @property bool $classification_confirmed
 * @property bool $tax_confirmed
 * @property string|null $imported_tax_code
 * @property-read Document $document
 */
class DocumentLine extends AccountingModel
{
    protected $table = 'accounting_document_lines';

    protected $fillable = [
        'document_id',
        'position',
        'description',
        'quantity',
        'unit',
        'unit_price_minor',
        'discount',
        'net_minor',
        'tax_code',
        'tax_rule_version_id',
        'tax_rate_bp',
        'tax_category',
        'tax_reason',
        'tax_recoverable',
        'tax_export_mapping',
        'tax_minor',
        'gross_minor',
        'account_role',
        'ledger_account_id',
        'catalog_item_id',
        'classification_code',
        'classification_confirmed',
        'tax_confirmed',
        'imported_tax_code',
        'service_from',
        'service_to',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_price_minor' => 'integer',
            'net_minor' => 'integer',
            'tax_rule_version_id' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_recoverable' => 'boolean',
            'tax_export_mapping' => 'array',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
            'ledger_account_id' => 'integer',
            'catalog_item_id' => 'integer',
            'classification_confirmed' => 'boolean',
            'tax_confirmed' => 'boolean',
            'service_from' => 'date',
            'service_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            $document = $line->document;

            if (! $document instanceof Document) {
                return;
            }

            if (in_array($document->document_status, [
                DocumentStatus::Issued,
                DocumentStatus::Received,
                DocumentStatus::Corrected,
                DocumentStatus::Cancelled,
            ], true)) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.document_line_immutable')
                );
            }
        });

        static::deleting(function (self $line): void {
            $document = $line->document;

            if ($document instanceof Document && in_array($document->document_status, [
                DocumentStatus::Issued,
                DocumentStatus::Received,
                DocumentStatus::Corrected,
                DocumentStatus::Cancelled,
            ], true)) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.document_line_immutable')
                );
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function taxRuleVersion(): BelongsTo
    {
        return $this->belongsTo(TaxRuleVersion::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
