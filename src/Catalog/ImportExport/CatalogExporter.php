<?php

namespace FilamentAccounting\Catalog\ImportExport;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\ExactMoney;

final class CatalogExporter
{
    public function __construct(private CatalogSerializer $serializer, private LegalEntityScope $scope, private AccountingAuthorizer $authorizer) {}

    public function export(string $path, string $format = 'xlsx', bool $template = false): void
    {
        $this->authorizer->authorize('manage_catalog');
        $entity = $this->scope->require();
        CatalogTransferSchema::format($format);
        $rows = $template ? [CatalogTransferSchema::example()] : CatalogItem::query()
            ->where('legal_entity_id', $entity->getKey())->orderBy('id')->limit(CatalogTransferSchema::MAX_ROWS + 1)
            ->get()->map(fn (CatalogItem $item): array => array_combine(CatalogTransferSchema::FIELDS, [
                $item->sku, $item->name, $item->description, $item->type->value, CatalogTransferUnits::label($item->unit),
                $item->default_quantity, ExactMoney::ofMinor($item->default_unit_price_minor, $item->currency)->decimalString(),
                $item->purchase_price_minor === null ? null : ExactMoney::ofMinor($item->purchase_price_minor, $item->currency)->decimalString(),
                $item->currency, $item->default_tax_code, $item->ean, $item->is_active,
            ]))->all();
        // Fail visibly on incompatible legacy data instead of producing an unimportable export.
        foreach ($rows as $index => $row) {
            CatalogTransferSchema::normalize($row, true, $index + 1, $entity->getKey());
        }
        $this->serializer->write($path, $format, $rows);
    }
}
