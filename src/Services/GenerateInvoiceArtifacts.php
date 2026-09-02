<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\LegalEntity;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use Illuminate\Support\Facades\Storage;

final class GenerateInvoiceArtifacts
{
    public function __construct(
        private readonly EInvoiceAdapter $eInvoice,
        private readonly InvoiceRenderer $renderer,
        private readonly StoreAttachment $attachments,
    ) {}

    /** @return array{pdf: Attachment, xml: Attachment} */
    public function handle(Document $document): array
    {
        if ($document->type !== DocumentType::SalesInvoice || $document->document_status !== DocumentStatus::Issued) {
            throw new DocumentException(__('filament-accounting::errors.only_issued_sales_invoice_exportable'));
        }

        $document->loadMissing('lines');
        $version = (string) (($document->legal_entity_snapshot ?? [])['invoice_template_version'] ?? $this->renderer->version());
        $existing = Attachment::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->getKey())
            ->whereIn('source_type', ['generated_pdf', 'generated_xml'])
            ->get()
            ->keyBy('source_type');
        if ($existing->has('generated_pdf') && $existing->has('generated_xml')
            && data_get($existing->get('generated_pdf')?->meta, 'renderer_version') === $this->renderer->version()
            && data_get($existing->get('generated_pdf')?->meta, 'template_version') === $version) {
            /** @var Attachment $pdf */
            $pdf = $existing->get('generated_pdf');
            /** @var Attachment $xml */
            $xml = $existing->get('generated_xml');

            return ['pdf' => $pdf, 'xml' => $xml];
        }

        $snapshot = $this->snapshot($document);
        $xml = $this->eInvoice->generate($snapshot);
        $basePdf = $this->renderer->render($snapshot);
        $pdf = (new ZugferdDocumentPdfMerger($xml, $basePdf))->generateDocument()->downloadString();
        $embeddedXml = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($pdf);
        if (! hash_equals(hash('sha256', $xml), hash('sha256', $embeddedXml))) {
            throw new DocumentException(__('filament-accounting::errors.embedded_xml_mismatch'));
        }

        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $meta = [
            'generated_at' => now()->toIso8601String(),
            'profile' => (string) config('filament-accounting.e_invoice.default_profile', 'en16931'),
            'renderer' => $this->renderer->key(),
            'renderer_version' => $this->renderer->version(),
            'template' => (string) (($document->legal_entity_snapshot ?? [])['invoice_template_key'] ?? 'default'),
            'template_version' => $version,
        ];
        $basename = 'invoice-'.($document->number ?: $document->uuid);
        $xmlAttachment = $this->attachments->handle($entity, $document, $basename.'.xml', $xml, 'generated_xml', $meta);

        try {
            $pdfAttachment = $this->attachments->handle($entity, $document, $basename.'.pdf', $pdf, 'generated_pdf', $meta + [
                'embedded_xml_sha256' => $xmlAttachment->sha256,
                'pdfa_part' => 3,
                'pdfa_conformance' => 'B',
            ]);
        } catch (\Throwable $exception) {
            $xmlAttachment->delete();
            Storage::disk($xmlAttachment->disk)->delete($xmlAttachment->path);
            throw $exception;
        }

        return ['pdf' => $pdfAttachment, 'xml' => $xmlAttachment];
    }

    /** @return array<string, mixed> */
    private function snapshot(Document $document): array
    {
        return [
            'number' => $document->number,
            'issue_date' => $document->issue_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'currency' => $document->currency,
            'net_minor' => $document->net_minor,
            'tax_minor' => $document->tax_minor,
            'gross_minor' => $document->gross_minor,
            'seller' => $document->legal_entity_snapshot ?? [],
            'buyer' => $document->party_snapshot ?? [],
            'seller_name' => (string) (($document->legal_entity_snapshot ?? [])['legal_name'] ?? ''),
            'buyer_name' => (string) (($document->party_snapshot ?? [])['legal_name'] ?? ''),
            'lines' => $document->lines->map(fn (DocumentLine $line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price_minor' => $line->unit_price_minor,
                'net_minor' => $line->net_minor,
                'tax_minor' => $line->tax_minor,
                'gross_minor' => $line->gross_minor,
                'tax_rate_bp' => $line->tax_rate_bp,
                'tax_category' => $line->tax_category,
                'tax_reason' => $line->tax_reason,
            ])->all(),
        ];
    }
}
