<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Tests\TestCase;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdDocumentValidator;
use horstoeko\zugferd\ZugferdXsdValidator;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class InvoiceArtifactTest extends TestCase
{
    #[Test]
    public function issuing_produces_private_pdfa3_and_byte_identical_invoice_xml(): void
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'vat_id' => 'DE123456789',
            'invoice_iban' => 'DE89370400440532013000',
            'invoice_bic' => 'COBADEFFXXX',
            'invoice_template_key' => 'default',
            'invoice_template_version' => '1',
        ]);
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [
                ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19'],
                ['description' => 'Books', 'quantity' => '1', 'unit_price' => '20.00', 'tax_code' => 'DE-7'],
            ],
        ], false);

        $artifacts = $document->attachments()->get()->keyBy('source_type');
        $this->assertCount(2, $artifacts);
        $pdfAttachment = $artifacts->get('generated_pdf');
        $xmlAttachment = $artifacts->get('generated_xml');
        $this->assertNotNull($pdfAttachment);
        $this->assertNotNull($xmlAttachment);
        $pdf = app(ReadAttachment::class)->handle($pdfAttachment);
        $xml = app(ReadAttachment::class)->handle($xmlAttachment);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
        $invoice = ZugferdDocumentReader::readAndGuessFromContent($xml);
        $this->assertTrue((new ZugferdXsdValidator($invoice))->validate()->hasNoValidationErrors());
        $this->assertCount(0, (new ZugferdDocumentValidator($invoice))->validateDocument());
        $this->assertSame($xml, ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($pdf));
        $this->assertSame(hash('sha256', $xml), $pdfAttachment->meta['embedded_xml_sha256']);
        $this->assertSame(3, $pdfAttachment->meta['pdfa_part']);
        $this->assertSame('1', $pdfAttachment->meta['renderer_version']);

        app(GenerateInvoiceArtifacts::class)->handle($document);
        $this->assertSame(2, $document->attachments()->count());
    }
}
