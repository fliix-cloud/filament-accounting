<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;

class ListBankTransfers extends ListRecords
{
    protected static string $resource = BankTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('filament-accounting::banking/fints/actions.new_transfer')),
        ];
    }
}
