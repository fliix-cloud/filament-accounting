<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;

class ListDirectDebitMandates extends ListRecords
{
    protected static string $resource = DirectDebitMandateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
