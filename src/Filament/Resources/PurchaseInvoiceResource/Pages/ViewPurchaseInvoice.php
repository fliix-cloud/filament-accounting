<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Support\DocumentAttachmentActions;
use FilamentAccounting\Filament\Support\DocumentSettlementActions;
use FilamentAccounting\Models\Document;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return $record instanceof Document
            ? [
                EditAction::make()->visible($record->document_status === DocumentStatus::Draft),
                ...DocumentAttachmentActions::make($record),
                ...DocumentSettlementActions::make($record),
            ]
            : [];
    }
}
