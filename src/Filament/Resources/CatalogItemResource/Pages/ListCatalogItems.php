<?php

namespace FilamentAccounting\Filament\Resources\CatalogItemResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Support\CatalogTransferActions;

class ListCatalogItems extends ListRecords
{
    protected static string $resource = CatalogItemResource::class;

    public string $catalogImportPreview = '';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), CatalogTransferActions::import(), CatalogTransferActions::export(), CatalogTransferActions::export(true)];
    }
}
