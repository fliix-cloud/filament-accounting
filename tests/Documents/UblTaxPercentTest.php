<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Documents\UblEInvoiceParser;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UblTaxPercentTest extends TestCase
{
    #[Test]
    public function ubl_tax_percent_converts_to_basis_points_without_floats(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
  <ID>INV-1</ID>
  <IssueDate>2026-03-10</IssueDate>
  <DocumentCurrencyCode>EUR</DocumentCurrencyCode>
  <InvoiceLine>
    <ID>1</ID>
    <InvoicedQuantity unitCode="C62">1</InvoicedQuantity>
    <LineExtensionAmount>100.00</LineExtensionAmount>
    <Item><Name>Service</Name>
      <ClassifiedTaxCategory><ID>S</ID><Percent>19.00</Percent></ClassifiedTaxCategory>
    </Item>
    <Price><PriceAmount>100.00</PriceAmount></Price>
  </InvoiceLine>
  <LegalMonetaryTotal>
    <TaxExclusiveAmount>100.00</TaxExclusiveAmount>
    <TaxInclusiveAmount>119.00</TaxInclusiveAmount>
  </LegalMonetaryTotal>
  <TaxTotal><TaxAmount>19.00</TaxAmount></TaxTotal>
</Invoice>
XML;

        $result = app(UblEInvoiceParser::class)->parse($xml, 'invoice.xml');

        $this->assertSame(1900, $result->lines[0]['tax_rate_bp'] ?? null);
    }
}
