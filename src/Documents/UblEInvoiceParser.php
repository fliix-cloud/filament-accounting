<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Support\ExactMoney;

final class UblEInvoiceParser
{
    public function supports(string $contents): bool
    {
        return str_contains($contents, 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
            || preg_match('/<(?:\w+:)?Invoice\b/', $contents) === 1;
    }

    public function parse(string $contents, string $filename): EInvoiceParseResult
    {
        $hash = hash('sha256', $contents);
        if (stripos($contents, '<!DOCTYPE') !== false) {
            return $this->invalid($contents, $hash, __('filament-accounting::errors.unsafe_xml'));
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement?->localName !== 'Invoice') {
            return $this->invalid($contents, $hash, implode('; ', $errors) ?: __('filament-accounting::errors.invalid_xml'));
        }

        $xpath = new \DOMXPath($document);
        $currency = strtoupper($this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='DocumentCurrencyCode']") ?: 'EUR');
        $lines = [];
        foreach ($xpath->query("//*[local-name()='InvoiceLine']") ?: [] as $lineNode) {
            $quantity = $this->value($xpath, ".//*[local-name()='InvoicedQuantity']", $lineNode) ?: '1';
            $unitPrice = $this->value($xpath, ".//*[local-name()='Price']/*[local-name()='PriceAmount']", $lineNode) ?: '0';
            $percent = $this->value($xpath, ".//*[local-name()='ClassifiedTaxCategory']/*[local-name()='Percent']", $lineNode);
            $quantityNode = $xpath->query(".//*[local-name()='InvoicedQuantity']", $lineNode)?->item(0);
            $lines[] = [
                'position' => $this->value($xpath, "./*[local-name()='ID']", $lineNode),
                'description' => $this->value($xpath, ".//*[local-name()='Item']/*[local-name()='Name']", $lineNode)
                    ?: $this->value($xpath, ".//*[local-name()='Item']/*[local-name()='Description']", $lineNode),
                'quantity' => $quantity,
                'unit' => $quantityNode instanceof \DOMElement ? $quantityNode->getAttribute('unitCode') : null,
                'unit_price' => $unitPrice,
                'tax_rate_bp' => $percent !== '' ? (int) round(((float) $percent) * 100) : null,
                'tax_category' => $this->value($xpath, ".//*[local-name()='ClassifiedTaxCategory']/*[local-name()='ID']", $lineNode),
                'line_net_minor' => $this->minor($this->value($xpath, "./*[local-name()='LineExtensionAmount']", $lineNode), $currency),
            ];
        }

        $number = $this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='ID']");
        $issueDate = $this->value($xpath, "/*[local-name()='Invoice']/*[local-name()='IssueDate']") ?: null;
        $supplier = $xpath->query("//*[local-name()='AccountingSupplierParty']")?->item(0);
        $sellerName = $supplier
            ? ($this->value($xpath, ".//*[local-name()='PartyName']/*[local-name()='Name']", $supplier)
                ?: $this->value($xpath, ".//*[local-name()='PartyLegalEntity']/*[local-name()='RegistrationName']", $supplier))
            : null;
        $sellerVatId = $supplier ? $this->value($xpath, ".//*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']", $supplier) : null;
        $net = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxExclusiveAmount']"), $currency);
        if ($net === 0) {
            $net = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='LineExtensionAmount']"), $currency);
        }
        $gross = $this->minor($this->value($xpath, "//*[local-name()='LegalMonetaryTotal']/*[local-name()='TaxInclusiveAmount']"), $currency);
        $tax = $this->minor($this->value($xpath, "//*[local-name()='TaxTotal']/*[local-name()='TaxAmount']"), $currency);
        $validationErrors = [];
        if ($number === '' || $issueDate === null || $lines === []) {
            $validationErrors[] = __('filament-accounting::errors.invalid_e_invoice');
        }

        return new EInvoiceParseResult(
            formatKey: 'ubl',
            documentNumber: $number,
            issueDate: $issueDate,
            currency: $currency,
            grossMinor: $gross,
            netMinor: $net,
            taxMinor: $tax,
            sellerName: $sellerName ?: null,
            sellerVatId: $sellerVatId ?: null,
            lines: $lines,
            originalXml: $contents,
            sha256: $hash,
            valid: $validationErrors === [],
            errors: $validationErrors,
            meta: ['filename' => $filename],
        );
    }

    private function value(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): string
    {
        return trim((string) $xpath->evaluate('string('.$expression.')', $context));
    }

    private function minor(string $value, string $currency): int
    {
        return $value === '' ? 0 : ExactMoney::ofString($value, $currency)->minorAmount;
    }

    private function invalid(string $contents, string $hash, string $error): EInvoiceParseResult
    {
        return new EInvoiceParseResult('ubl', '', null, 'EUR', 0, 0, 0, null, null, [], $contents, $hash, false, [$error]);
    }
}
