<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Documents\Data\PurchaseInvoiceUploadResult;
use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Enums\PartyAddressRole;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Models\PartyTaxId;
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
        [$party, $match, $supplierCreated] = $parsed ? $this->matchSupplier($entity, $parsed) : [null, 'unmatched', false];
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
        $document = null;
        try {
            $document = $this->invoices->createDraft($entity, [
                'party_id' => $party?->getKey(),
                'supplier_invoice_number' => $parsed?->documentNumber ?: null,
                'issue_date' => $parsed?->issueDate,
                'currency' => $parsed instanceof EInvoiceParseResult ? $parsed->currency : $entity->base_currency,
                'lines' => $lines,
                'e_invoice_meta' => $meta,
                'idempotency_key' => $hash,
            ]);
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
            if ($document instanceof Document && ! $existingDraft instanceof Document) {
                $this->cleanup($document);
            }
            if ($supplierCreated && $party instanceof Party && ! $party->documents()->exists()) {
                $party->delete();
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

    /** @return array{0: Party|null, 1: string, 2: bool} */
    private function matchSupplier(LegalEntity $entity, EInvoiceParseResult $parsed): array
    {
        $suppliers = Party::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_supplier', true)
            ->with('taxIds');
        if (filled($parsed->sellerVatId)) {
            $vatId = $this->normalizeIdentifier($parsed->sellerVatId);
            $matches = (clone $suppliers)->whereHas('taxIds')->get()
                ->filter(fn (Party $party): bool => $party->taxIds->contains(
                    fn (PartyTaxId $taxId): bool => $this->normalizeIdentifier($taxId->number) === $vatId
                ));
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched', false];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous', false];
            }
        }
        if (filled($parsed->sellerName)) {
            $needle = $this->normalizeName($parsed->sellerName);
            $matches = $suppliers->get()->filter(fn (Party $party): bool => $this->normalizeName($party->legal_name) === $needle);
            if ($matches->count() === 1) {
                return [$matches->first(), 'matched', false];
            }
            if ($matches->count() > 1) {
                return [null, 'ambiguous', false];
            }

            $party = Party::query()->create([
                'legal_entity_id' => $entity->getKey(),
                'kind' => 'organization',
                'is_customer' => false,
                'is_supplier' => true,
                'legal_name' => trim((string) $parsed->sellerName),
                'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                'email' => $parsed->sellerEmail,
                'default_currency' => $parsed->currency,
                'is_active' => true,
            ]);

            if (collect([$parsed->sellerAddressLine1, $parsed->sellerAddressLine2, $parsed->sellerPostalCode, $parsed->sellerCity])->filter()->isNotEmpty()) {
                PartyAddress::query()->create([
                    'party_id' => $party->getKey(),
                    'line1' => $parsed->sellerAddressLine1,
                    'line2' => $parsed->sellerAddressLine2,
                    'postal_code' => $parsed->sellerPostalCode,
                    'city' => $parsed->sellerCity,
                    'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                    'address_role' => PartyAddressRole::Both,
                    'is_primary' => true,
                ]);
            }

            if (filled($parsed->sellerVatId)) {
                PartyTaxId::query()->create([
                    'party_id' => $party->getKey(),
                    'type' => 'vat',
                    'number' => trim((string) $parsed->sellerVatId),
                    'country_code' => filled($parsed->sellerCountryCode) ? strtoupper((string) $parsed->sellerCountryCode) : null,
                ]);
            }

            return [$party, 'created', true];
        }

        return [null, 'unmatched', false];
    }

    private function normalizeIdentifier(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $value));
    }

    private function normalizeName(?string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim((string) $value)));
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
