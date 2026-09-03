<?php

namespace FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithBankAccountSync;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;

class ListAccountingBankAccounts extends ListRecords
{
    use InteractsWithBankAccountSync;

    protected static string $resource = AccountingBankAccountResource::class;
}
