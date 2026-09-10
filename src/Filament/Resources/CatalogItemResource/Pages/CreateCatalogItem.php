<?php

namespace FilamentAccounting\Filament\Resources\CatalogItemResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\ExactMoney;

class CreateCatalogItem extends CreateRecord
{
    protected static string $resource = CatalogItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();
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
