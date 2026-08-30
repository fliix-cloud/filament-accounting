<?php

namespace FilamentAccounting\Filament\Resources\CatalogItemResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Ownership\LegalEntityScope;

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

        return $data;
    }
}
