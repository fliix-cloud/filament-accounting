<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;

/** Retains the legacy service name; discards the draft without deleting evidence. */
final class DeletePurchaseInvoiceDraft
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Document $document, string $reason): bool
    {
        return $document->getConnection()->transaction(function () use ($document, $reason): bool {
            // Match the ledger/audit lock order: entity before business record.
            $entity = LegalEntity::query()->lockForUpdate()->findOrFail($document->getRawOriginal('legal_entity_id'));
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
            $this->scope->assertModel($document);
            $this->scope->assertSame($entity->getKey());
            $this->authorizer->authorize('discard_purchase_invoices', $document);

            if ($document->type !== DocumentType::PurchaseInvoice
                || $document->document_status !== DocumentStatus::Draft
                || $document->posting_status !== PostingStatus::Unposted) {
                throw new DocumentException(__('filament-accounting::errors.only_purchase_invoice_draft_deletable'));
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new DocumentException(__('filament-accounting::errors.reason_required'));
            }

            $document->document_status = DocumentStatus::Discarded;
            $document->save();

            $this->audit->log($entity, 'document.purchase_draft_discarded', $document, [
                'before' => DocumentStatus::Draft->value,
                'after' => DocumentStatus::Discarded->value,
                'attachments' => $document->attachments()->get(['uuid', 'sha256'])->toArray(),
            ], $reason);

            return true;
        });
    }
}
