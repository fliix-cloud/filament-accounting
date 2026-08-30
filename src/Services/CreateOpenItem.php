<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\OpenItem;

final class CreateOpenItem
{
    public function handle(Document $document): OpenItem
    {
        $existing = OpenItem::query()->where('document_id', $document->getKey())->first();
        if ($existing instanceof OpenItem) {
            return $existing;
        }

        if (! $document->party_id) {
            throw new DocumentException(__('filament-accounting::errors.party_not_found'));
        }

        $kind = match ($document->type) {
            DocumentType::SalesInvoice, DocumentType::SalesCreditNote => OpenItemKind::Receivable,
            DocumentType::PurchaseInvoice, DocumentType::PurchaseCreditNote => OpenItemKind::Payable,
        };

        $item = new OpenItem;
        $item->fill([
            'legal_entity_id' => $document->legal_entity_id,
            'document_id' => $document->getKey(),
            'party_id' => $document->party_id,
            'kind' => $kind,
            'currency' => $document->currency,
            'original_minor' => (int) $document->gross_minor,
            'due_on' => $document->due_date?->toDateString(),
            'is_reversed' => false,
        ]);
        $item->save();

        return $item;
    }

    public function paymentStatus(Document $document): PaymentStatus
    {
        $item = $document->openItem;

        if (! $item instanceof OpenItem) {
            return PaymentStatus::Unpaid;
        }

        return $item->derivedPaymentStatus();
    }
}
