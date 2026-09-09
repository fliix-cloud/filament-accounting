<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
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
    public function pdf_failure_retains_xml_and_original_error_and_repeated_generation_stays_blocked(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $failure = new \RuntimeException('PDF metadata unavailable');
        Attachment::creating(function (Attachment $attachment) use ($failure): void {
            if ($attachment->source_type === 'generated_pdf') {
                throw $failure;
            }
        });
        try {
            app(GenerateInvoiceArtifacts::class)->handle($document);
            $this->fail('The PDF failure must propagate unchanged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $xml = $document->attachments()->sole();
        $this->assertSame('generated_xml', $xml->source_type);
        $original = app(ReadAttachment::class)->handle($xml);
        $disk = Storage::disk('accounting-artifacts');
        $files = $disk->allFiles();
        $this->assertCount(2, $files);
        $retained = array_map(fn (string $path) => $disk->get($path), $files);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(GenerateInvoiceArtifacts::class)->handle($document);
                $this->fail('A partial pair must not be regenerated or reported complete.');
            } catch (DocumentException $exception) {
                $this->assertSame(__('filament-accounting::errors.invoice_originals_incomplete'), $exception->getMessage());
            }
            $this->assertSame($original, $disk->get($xml->path));
            $this->assertSame($retained, array_map(fn (string $path) => $disk->get($path), $files));
            $this->assertSame($files, $disk->allFiles());
            $this->assertDatabaseCount('accounting_attachments', 1);
        }
    }

    #[Test]
    public function artifact_retry_checks_both_files_and_never_replaces_missing_or_corrupt_bytes(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $service = app(GenerateInvoiceArtifacts::class);
        $artifacts = $service->handle($document);
        $disk = Storage::disk('accounting-artifacts');
        foreach ($artifacts as $attachment) {
            $original = $disk->get($attachment->path);
            foreach (['corrupted', null] as $contents) {
                $contents === null ? $disk->delete($attachment->path) : $disk->put($attachment->path, $contents);
                try {
                    $service->handle($document);
                    $this->fail('Broken evidence must block generation.');
                } catch (AccountingException $exception) {
                    $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
                }
                $this->assertSame($contents, $disk->get($attachment->path));
                $this->assertDatabaseCount('accounting_attachments', 2);
            }
            $disk->put($attachment->path, $original);
        }
    }

    #[Test]
    public function renderer_upgrade_reuses_the_verified_issued_pair(): void
    {
        $document = $this->issuedWithoutArtifacts();
        $first = app(GenerateInvoiceArtifacts::class)->handle($document);
        $renderer = \Mockery::mock(InvoiceRenderer::class);
        $renderer->shouldReceive('version')->andReturn('2');
        $renderer->shouldNotReceive('render');
        $this->app->instance(InvoiceRenderer::class, $renderer);

        $retry = app(GenerateInvoiceArtifacts::class)->handle($document);
        foreach (['pdf', 'xml'] as $type) {
            $this->assertSame($first[$type]->getKey(), $retry[$type]->getKey());
            $this->assertSame($first[$type]->path, $retry[$type]->path);
            $this->assertSame($first[$type]->sha256, $retry[$type]->sha256);
        }
        $this->assertDatabaseCount('accounting_attachments', 2);
    }

    private function issuedWithoutArtifacts(): Document
    {
        Storage::fake('accounting-artifacts');
        config()->set('filament-accounting.storage.disk', 'accounting-artifacts');
        config()->set('filament-accounting.e_invoice.generate_on_issue', false);
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1', 'postal_code' => '10115',
            'city' => 'Berlin', 'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser());

        return app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19']],
        ], false);
    }

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
        $this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertStringContainsString('/Type /OutputIntent', $pdf);
        $this->assertStringContainsString('/AFRelationship /Data', $pdf);
        $this->assertStringContainsString('factur-x.xml', $pdf);
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
