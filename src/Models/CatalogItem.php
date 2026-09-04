<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\RichText;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string|null $sku
 * @property CatalogItemType $type
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property string $default_quantity
 * @property int $default_unit_price_minor
 * @property string $currency
 * @property string|null $default_account_role
 * @property string|null $default_tax_code
 * @property bool $is_active
 */
class CatalogItem extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_catalog_items';

    protected $fillable = [
        'legal_entity_id',
        'sku',
        'type',
        'name',
        'description',
        'unit',
        'default_quantity',
        'default_unit_price_minor',
        'currency',
        'default_account_role',
        'default_tax_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CatalogItemType::class,
            'default_unit_price_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function setDescriptionAttribute(mixed $value): void
    {
        $this->attributes['description'] = RichText::sanitize(is_string($value) ? $value : null);
    }
}
