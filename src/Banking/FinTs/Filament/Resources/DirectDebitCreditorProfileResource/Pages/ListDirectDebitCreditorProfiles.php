<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;

class ListDirectDebitCreditorProfiles extends ListRecords
{
    protected static string $resource = DirectDebitCreditorProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
