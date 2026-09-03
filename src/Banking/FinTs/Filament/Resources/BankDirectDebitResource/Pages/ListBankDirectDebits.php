<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;

class ListBankDirectDebits extends ListRecords
{
    protected static string $resource = BankDirectDebitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('filament-accounting::banking/fints/actions.new_direct_debit'))];
    }
}
