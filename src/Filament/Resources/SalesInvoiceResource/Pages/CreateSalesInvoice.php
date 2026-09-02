<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use Illuminate\Database\Eloquent\Model;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $entity = app(LegalEntityScope::class)->require();

        return app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $data['party_id'],
            'issue_date' => $data['issue_date'],
            'supply_date' => $data['supply_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'currency' => $data['currency'],
            'lines' => $data['lines'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();
        $data['type'] = DocumentType::SalesInvoice;
        $data['direction'] = DocumentDirection::Outgoing;

        return $data;
    }
}
