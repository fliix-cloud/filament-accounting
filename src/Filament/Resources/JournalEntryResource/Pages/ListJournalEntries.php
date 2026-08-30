<?php

namespace FilamentAccounting\Filament\Resources\JournalEntryResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\JournalEntryResource;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;
}
