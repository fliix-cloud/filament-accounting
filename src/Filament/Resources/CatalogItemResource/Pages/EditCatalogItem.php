<?php

namespace FilamentAccounting\Filament\Resources\CatalogItemResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Support\ExactMoney;

class EditCatalogItem extends EditRecord
{
    protected static string $resource = CatalogItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['default_unit_price'] = ExactMoney::ofMinor(
            (int) $data['default_unit_price_minor'],
            (string) $data['currency'],
        )->decimalString();
        $data['purchase_price'] = isset($data['purchase_price_minor'])
            ? ExactMoney::ofMinor((int) $data['purchase_price_minor'], (string) $data['currency'])->decimalString() : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_unit_price_minor'] = ExactMoney::ofString(
            (string) $data['default_unit_price'],
            (string) $data['currency'],
        )->minorAmount;
        unset($data['default_unit_price']);
        $data['purchase_price_minor'] = filled($data['purchase_price'] ?? null)
            ? ExactMoney::ofString((string) $data['purchase_price'], (string) $data['currency'])->minorAmount : null;
        unset($data['purchase_price']);

        return $data;
    }
}
