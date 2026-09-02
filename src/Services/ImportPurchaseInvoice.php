<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Documents\Data\PurchaseInvoiceUploadResult;
use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use Illuminate\Support\Facades\Storage;

final class ImportPurchaseInvoice
{
    public function __construct(
        private readonly ZugferdEInvoiceAdapter $cii,
        private readonly UblEInvoiceParser $ubl,
        private readonly RegisterPurchaseInvoice $invoices,
        private readonly StoreAttachment $attachments,
    ) {}

    public function handle(LegalEntity $entity, string $filename, string $contents): PurchaseInvoiceUploadResult
    {
        $hash = hash('sha256', $contents);
        $retry = Attachment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('sha256', $hash)
            ->where('source_type', 'original_invoice')
            ->first();
        if ($retry?->attachable instanceof Document) {
            $document = $retry->attachable;

            return new PurchaseInvoiceUploadResult(
                $document,
                (bool) data_get($document->e_invoice_meta, 'structured', false),
                (string) data_get($document->e_invoice_meta, 'format', 'pdf'),
                (string) data_get($document->e_invoice_meta, 'supplier_match', 'unmatched'),
                true,
            );
        }

        [$parsed, $embeddedXml, $format] = $this->inspect($filename, $contents);
        [$party, $match] = $parsed ? $this->matchSupplier($entity, $parsed) : [null, 'unmatched'];
        $lines = $parsed ? array_map(fn (array $line): array => $this->importedLine($line), $parsed->lines) : [];
        $meta = [
            'structured' => $parsed instanceof EInvoiceParseResult,
            'format' => $format,
            'validated' => $parsed instanceof EInvoiceParseResult && $parsed->valid,
            'supplier_match' => $match,
            'source_sha256' => $hash,
            'source_totals' => $parsed ? [
                'net_minor' => $parsed->netMinor,
                'tax_minor' => $parsed->taxMinor,
                'gross_minor' => $parsed->grossMinor,
            ] : null,
        ];
        $existingDraft = Document::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('idempotency_key', $hash)
            ->first();
        $document = $this->invoices->createDraft($entity, [
            'party_id' => $party?->getKey(),
            'supplier_invoice_number' => $parsed?->documentNumber ?: null,
            'issue_date' => $parsed?->issueDate,
            'currency' => $parsed instanceof EInvoiceParseResult ? $parsed->currency : $entity->base_currency,
            'lines' => $lines,
            'e_invoice_meta' => $meta,
            'idempotency_key' => $hash,
        ]);

        try {
            $this->attachments->handle($entity, $document, $filename, $contents, 'original_invoice', [
                'format' => $format,
                'structured' => $parsed instanceof EInvoiceParseResult,
            ]);
            if ($embeddedXml !== null) {
                $this->attachments->handle(
                    $entity,
                    $document,
                    pathinfo($filename, PATHINFO_FILENAME).'-embedded.xml',
                    $embeddedXml,
                    'embedded_e_invoice',
                    ['format' => $parsed?->formatKey, 'extracted_from_sha256' => $hash],
                );
            }
        } catch (\Throwable $exception) {
            if (! $existingDraft instanceof Document) {
                $this->cleanup($document);
            }
            throw $exception;
        }

        return new PurchaseInvoiceUploadResult($document->fresh(['lines', 'attachments']) ?? $document, $parsed instanceof EInvoiceParseResult, $format, $match);
    }

    /** @return array{0: EInvoiceParseResult|null, 1: string|null, 2: string} */
    private function inspect(string $filename, string $contents): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'pdf') {
            if (! str_starts_with($contents, '%PDF-')) {
                throw new DocumentException(__('filament-accounting::errors.invalid_pdf'));
            }
            try {
                $embedded = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($contents);
            } catch (\Throwable) {
                return [null, null, 'pdf'];
            }
            $parsed = $this->parseXml($embedded, $filename.'#embedded.xml');

            return [$parsed, $embedded, 'hybrid-'.$parsed->formatKey];
        }
        if ($extension === 'xml') {
            $parsed = $this->parseXml($contents, $filename);

            return [$parsed, null, $parsed->formatKey];
        }

        throw new DocumentException(__('filament-accounting::errors.unsupported_attachment_type'));
    }

    private function parseXml(string $contents, string $filename): EInvoiceParseResult
    {
        $parsed = match (true) {
            $this->cii->supports('application/xml', $contents) => $this->cii->parse($contents, $filename),
            $this->ubl->supports($contents) => $this->ubl->parse($contents, $filename),
            default => null,
        };
        if (! $parsed instanceof EInvoiceParseResult) {
            throw new DocumentException(__('filament-accounting::errors.invalid_e_invoice'));
        }
        if (! $parsed->valid) {
            throw new DocumentException(implode('; ', $parsed->errors));
        }

        return $parsed;
    }

    /** @return array{0: Party|null, 1: string} */
    private function matchSupplier(LegalEntity $entity, EInvoiceParseResult $parsed): array
    {
        $suppliers = Party::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_supplier', true)
            ->where('is_active', true);
        if (filled($parsed->sellerVatId)) {
            $matches = (clone $suppliers)->whereHas('taxIds', fn ($query) => $query->where('number', $parsed->sellerVatId))->get();
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched'];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous'];
            }
        }
        if (filled($parsed->sellerName)) {
            $needle = mb_strtolower(trim((string) $parsed->sellerName));
            $matches = $suppliers->get()->filter(fn (Party $party): bool => mb_strtolower(trim($party->legal_name)) === $needle);
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched'];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous'];
            }
        }

        return [null, 'unmatched'];
    }

    /** @param array<string, mixed> $line */
    private function importedLine(array $line): array
    {
        $rate = isset($line['tax_rate_bp']) ? (int) $line['tax_rate_bp'] : 0;
        $taxCode = match ($rate) {
            1900 => 'DE-19',
            700 => 'DE-7',
            0 => 'DE-0',
            default => null,
        };

        return [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => (string) ($line['quantity'] ?? '1'),
            'unit' => $line['unit'] ?? null,
            'unit_price' => (string) ($line['unit_price'] ?? '0'),
            'tax_code' => $taxCode,
            'imported_tax_code' => $taxCode,
            'imported_tax_rate_bp' => $rate,
            'classification_code' => null,
            'classification_confirmed' => false,
            'tax_confirmed' => false,
        ];
    }

    private function cleanup(Document $document): void
    {
        $attachments = Attachment::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->getKey())
            ->get();
        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        }
        $document->lines()->delete();
        $document->delete();
    }
}
