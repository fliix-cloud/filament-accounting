<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;

class ListPurchaseInvoices extends ListRecords
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public function hydrate(): void
    {
        // Keep confirmation modals and retained rows in one complete render.
        // Partial action-modal rendering can otherwise produce an empty root.
        $this->forceRender();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
