<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $entity = app(LegalEntityScope::class)->require();

        return app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $data['party_id'],
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'issue_date' => $data['issue_date'],
            'receipt_date' => $data['receipt_date'] ?? null,
            'currency' => $data['currency'],
            'lines' => $data['lines'] ?? [],
        ]);
    }
}
