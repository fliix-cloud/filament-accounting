<?php

namespace FilamentAccounting\Filament\Resources\SupplierResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\SupplierResource;
use FilamentAccounting\Ownership\LegalEntityScope;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();
        $data['is_supplier'] = true;

        return $data;
    }
}
