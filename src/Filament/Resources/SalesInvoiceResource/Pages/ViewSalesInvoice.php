<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Support\DocumentSettlementActions;
use FilamentAccounting\Models\Document;

class ViewSalesInvoice extends ViewRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return $record instanceof Document ? DocumentSettlementActions::make($record) : [];
    }
}
