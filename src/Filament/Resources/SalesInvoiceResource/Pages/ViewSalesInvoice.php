<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Support\DocumentAttachmentActions;
use FilamentAccounting\Filament\Support\DocumentSettlementActions;
use FilamentAccounting\Filament\Support\SalesInvoiceCompletionAction;
use FilamentAccounting\Models\Document;

class ViewSalesInvoice extends ViewRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return $record instanceof Document
            ? [EditAction::make()->visible(fn (Document $record): bool => SalesInvoiceResource::canEdit($record)), SalesInvoiceResource::deleteDraftAction(), SalesInvoiceResource::issueAction(), SalesInvoiceResource::generateArtifactsAction(),
                SalesInvoiceCompletionAction::make(), ...DocumentAttachmentActions::make($record), ...DocumentSettlementActions::make($record)]
            : [];
    }
}
