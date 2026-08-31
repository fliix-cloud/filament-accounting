<?php

namespace FilamentAccounting\Filament\Resources\CustomerResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\CustomerResource;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Role flags are controlled by the resource, never by editable form state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['is_customer'], $data['is_supplier']);

        return $data;
    }
}
