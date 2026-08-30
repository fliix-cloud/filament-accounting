<?php

namespace FilamentAccounting\Filament\Resources\CustomerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Ownership\LegalEntityScope;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();
        $data['is_customer'] = true;

        return $data;
    }
}
