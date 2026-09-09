<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;

final class VerifyPurchaseInvoiceOriginals
{
    public function __construct(private readonly VerifyAttachmentIntegrity $integrity) {}

    public function handle(Document $document): void
    {
        $meta = $document->e_invoice_meta ?? [];
        if (! array_key_exists('source_sha256', $meta)) {
            return; // Manually registered invoices do not have an import manifest.
        }

        $expected = ['original_invoice' => $meta['source_sha256']];
        if (($meta['structured'] ?? false) && ($meta['original_format'] ?? 'pdf') !== 'xml') {
            $source = str_starts_with((string) ($meta['format'] ?? ''), 'hybrid-')
                ? 'embedded_e_invoice' : 'supplied_e_invoice';
            $expected[$source] = $meta['structured_sha256'] ?? null;
        }

        foreach ($expected as $source => $hash) {
            $attachments = $document->attachments()
                ->where('legal_entity_id', $document->legal_entity_id)
                ->where('source_type', $source)->get();
            $attachment = $attachments->first();
            if (! is_string($hash) || $attachments->count() !== 1 || ! $attachment instanceof Attachment
                || ! hash_equals($hash, $attachment->sha256)) {
                throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
            }
            $this->integrity->handle($attachment);
        }
    }
}
