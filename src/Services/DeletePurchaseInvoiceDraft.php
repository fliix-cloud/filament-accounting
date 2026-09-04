<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeletePurchaseInvoiceDraft
{
    public function handle(Document $document): bool
    {
        if ($document->type !== DocumentType::PurchaseInvoice || $document->document_status !== DocumentStatus::Draft) {
            throw new DocumentException(__('filament-accounting::errors.only_purchase_invoice_draft_deletable'));
        }

        $attachments = Attachment::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->getKey())
            ->get();

        DB::transaction(function () use ($document): void {
            $document->lines()->delete();
            $document->attachments()->delete();
            $document->delete();
        });

        $attachments->each(function (Attachment $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });

        return true;
    }
}
