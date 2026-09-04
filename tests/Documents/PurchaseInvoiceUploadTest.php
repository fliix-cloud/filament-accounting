<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class PurchaseInvoiceUploadTest extends TestCase
{
    #[Test]
    public function standalone_ubl_creates_one_unconfirmed_prefilled_draft_and_retries_idempotently(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);
        PartyTaxId::query()->create(['party_id' => $supplier->getKey(), 'type' => 'vat', 'number' => 'DE999999999', 'country_code' => 'DE']);
        $xml = $this->ublInvoice();

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.xml', $xml);
        $retry = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.xml', $xml);

        $this->assertTrue($result->structured);
        $this->assertSame('ubl', $result->format);
        $this->assertSame('matched', $result->supplierMatch);
        $this->assertTrue($retry->idempotentRetry);
        $this->assertSame($result->document->getKey(), $retry->document->getKey());
        $this->assertSame(DocumentStatus::Draft, $result->document->document_status);
        $this->assertSame($supplier->getKey(), $result->document->party_id);
        $this->assertSame('INV-42', $result->document->supplier_invoice_number);
        $this->assertSame(10000, $result->document->net_minor);
        $this->assertSame('DE-19', $result->document->lines->firstOrFail()->tax_code);
        $this->assertFalse($result->document->lines->firstOrFail()->classification_confirmed);
        $this->assertFalse($result->document->lines->firstOrFail()->tax_confirmed);
        $this->assertCount(1, $result->document->attachments);
        $this->assertSame($xml, app(ReadAttachment::class)->handle($result->document->attachments->firstOrFail()));

        $this->expectException(DocumentException::class);
        app(RegisterPurchaseInvoice::class)->receive($result->document);
    }

    #[Test]
    public function standalone_ubl_creates_an_unambiguous_missing_supplier(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.xml', $this->ublInvoice());
        $supplier = Party::query()->where('legal_entity_id', $entity->getKey())->where('is_supplier', true)->sole();

        $this->assertSame('created', $result->supplierMatch);
        $this->assertSame($supplier->getKey(), $result->document->party_id);
        $this->assertSame('Vendor GmbH', $supplier->legal_name);
        $this->assertSame('EUR', $supplier->default_currency);
        $this->assertSame('DE999999999', $supplier->taxIds()->sole()->number);
    }

    #[Test]
    public function standalone_ubl_keeps_an_ambiguous_supplier_unassigned(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);
        $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);

        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'vendor-42.xml', $this->ublInvoice());

        $this->assertSame('ambiguous', $result->supplierMatch);
        $this->assertNull($result->document->party_id);
        $this->assertSame(2, Party::query()->where('legal_entity_id', $entity->getKey())->where('is_supplier', true)->count());
    }

    #[Test]
    public function hybrid_pdf_keeps_the_original_and_extracted_xml_while_plain_pdf_starts_empty(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        $salesInvoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [['description' => 'Hybrid source', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'DE-19']],
        ], false);
        $hybridPdf = app(ReadAttachment::class)->handle($salesInvoice->attachments()->where('source_type', 'generated_pdf')->firstOrFail());

        $hybrid = app(ImportPurchaseInvoice::class)->handle($entity, 'hybrid.pdf', $hybridPdf);
        $plain = app(ImportPurchaseInvoice::class)->handle($entity, 'plain.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        $this->assertTrue($hybrid->structured);
        $this->assertStringStartsWith('hybrid-', $hybrid->format);
        $this->assertCount(2, $hybrid->document->attachments);
        $this->assertFalse($plain->structured);
        $this->assertSame('pdf', $plain->format);
        $this->assertCount(0, $plain->document->lines);
        $this->assertCount(1, $plain->document->attachments);
    }

    #[Test]
    public function dangerous_xml_is_rejected_before_any_document_or_object_is_created(): void
    {
        Storage::fake('purchase-imports');
        config()->set('filament-accounting.storage.disk', 'purchase-imports');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'unsafe.xml', '<!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Invoice>&xxe;</Invoice>');
            $this->fail('XXE input must be rejected.');
        } catch (DocumentException) {
            $this->assertDatabaseCount('accounting_documents', 0);
            $this->assertDatabaseCount('accounting_attachments', 0);
            $this->assertSame([], Storage::disk('purchase-imports')->allFiles());
        }
    }

    private function ublInvoice(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-42</cbc:ID><cbc:IssueDate>2026-03-10</cbc:IssueDate><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty><cac:Party><cac:PartyName><cbc:Name>Vendor GmbH</cbc:Name></cac:PartyName><cac:PartyTaxScheme><cbc:CompanyID>DE999999999</cbc:CompanyID></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>
  <cac:TaxTotal><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount></cac:TaxTotal>
  <cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">100.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">119.00</cbc:TaxInclusiveAmount></cac:LegalMonetaryTotal>
  <cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="C62">1</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cac:Item><cbc:Name>Hosting</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount currencyID="EUR">100.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>
XML;
    }
}
