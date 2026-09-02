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

        return $data;
    }
}
