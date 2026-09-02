<?php

namespace FilamentAccounting\Filament\Resources\TaxCodeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\TaxCodeResource;
use FilamentAccounting\Ownership\LegalEntityScope;

class CreateTaxCode extends CreateRecord
{
    protected static string $resource = TaxCodeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();

        return $data;
    }
}
