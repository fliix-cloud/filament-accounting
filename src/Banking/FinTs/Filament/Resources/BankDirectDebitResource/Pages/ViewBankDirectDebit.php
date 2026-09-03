<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;

class ViewBankDirectDebit extends ViewRecord
{
    protected static string $resource = BankDirectDebitResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('debtor_name'),
            TextEntry::make('debtor_iban'),
            TextEntry::make('amount'),
            TextEntry::make('mandate_id'),
            TextEntry::make('status')->badge(),
        ]);
    }
}
