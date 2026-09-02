<?php

namespace FilamentAccounting\Filament\Resources\TaxCodeResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\TaxCodeResource;

class ListTaxCodes extends ListRecords
{
    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
