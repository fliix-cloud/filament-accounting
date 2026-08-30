<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Support\DocumentSettlementActions;
use FilamentAccounting\Models\Document;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return $record instanceof Document ? DocumentSettlementActions::make($record) : [];
    }
}
