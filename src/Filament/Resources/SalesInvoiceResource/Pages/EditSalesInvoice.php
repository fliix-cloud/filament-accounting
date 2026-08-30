<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\Document;

class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return ! ($record instanceof Document && $record->isIssuedOrReceived());
    }
}
