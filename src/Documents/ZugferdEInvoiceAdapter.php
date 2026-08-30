<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Documents\Data\EInvoiceParseResult;
use FilamentAccounting\Support\ExactMoney;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdProfiles;

final class ZugferdEInvoiceAdapter implements EInvoiceAdapter
{
    public function formatKey(): string
    {
        return 'zugferd';
    }

    public function supports(string $mimeType, string $contents): bool
    {
        if (! str_contains($mimeType, 'xml') && ! str_contains($contents, 'CrossIndustryInvoice')) {
            return false;
        }

        return str_contains($contents, 'CrossIndustryInvoice') || str_contains($contents, 'rsm:ExchangedDocument');
    }

    public function parse(string $contents, string $filename): EInvoiceParseResult
    {
        $hash = hash('sha256', $contents);

        try {
            $reader = ZugferdDocumentReader::readAndGuessFromContent($contents);
        } catch (\Throwable $e) {
            return new EInvoiceParseResult(
                formatKey: $this->formatKey(),
                documentNumber: '',
                issueDate: null,
                currency: 'EUR',
                grossMinor: 0,
                netMinor: 0,
                taxMinor: 0,
                sellerName: null,
                sellerVatId: null,
                lines: [],
                originalXml: $contents,
                sha256: $hash,
                valid: false,
                errors: [$e->getMessage()],
            );
        }

        $documentNo = null;
        $documentTypeCode = null;
        $documentDate = null;
        $invoiceCurrency = null;
        $taxCurrency = null;
        $documentName = null;
        $documentLanguage = null;
        $period = null;
        $reader->getDocumentInformation($documentNo, $documentTypeCode, $documentDate, $invoiceCurrency, $taxCurrency, $documentName, $documentLanguage, $period);

        $sellerName = null;
        $sellerIds = null;
        $sellerDescription = null;
        $reader->getDocumentSeller($sellerName, $sellerIds, $sellerDescription);
        $lineOne = null;
        $lineTwo = null;
        $lineThree = null;
        $sellerPostcode = null;
        $sellerCity = null;
        $sellerCountry = null;
        $subDivision = null;
        $reader->getDocumentSellerAddress($lineOne, $lineTwo, $lineThree, $sellerPostcode, $sellerCity, $sellerCountry, $subDivision);

        $taxReg = null;
        $reader->getDocumentSellerTaxRegistration($taxReg);
        $vatId = is_array($taxReg) ? ($taxReg['VA'] ?? $taxReg['FC'] ?? null) : null;

        $grand = null;
        $lineTotal = null;
        $taxBasis = null;
        $taxTotal = null;
        $allowance = null;
        $charge = null;
        $prepaid = null;
        $rounding = null;
        $due = null;
        $reader->getDocumentSummation($grand, $due, $lineTotal, $charge, $allowance, $taxBasis, $taxTotal, $rounding, $prepaid);

        $currency = strtoupper((string) ($invoiceCurrency ?: 'EUR'));
        $gross = $this->toMinor($grand, $currency);
        $net = $this->toMinor($taxBasis ?? $lineTotal, $currency);
        $tax = $this->toMinor($taxTotal, $currency);

        $lines = [];
        $reader->firstDocumentPosition();
        do {
            $lineId = null;
            $lineStatusCode = null;
            $lineStatusReason = null;
            $reader->getDocumentPositionGenerals($lineId, $lineStatusCode, $lineStatusReason);
            $quantity = null;
            $unitCode = null;
            $chargeFreeQty = null;
            $chargeFreeUnit = null;
            $packageQty = null;
            $packageUnit = null;
            $reader->getDocumentPositionQuantity($quantity, $unitCode, $chargeFreeQty, $chargeFreeUnit, $packageQty, $packageUnit);
            $name = null;
            $description = null;
            $sellerNumber = null;
            $buyerNumber = null;
            $globalIdType = null;
            $globalId = null;
            $reader->getDocumentPositionProductDetails($name, $description, $sellerNumber, $buyerNumber, $globalIdType, $globalId);
            $netLine = null;
            $basisQty = null;
            $basisUnit = null;
            $reader->getDocumentPositionNetPrice($netLine, $basisQty, $basisUnit);
            $lines[] = [
                'position' => $lineId,
                'description' => $name ?: $description,
                'quantity' => $quantity !== null ? (string) $quantity : '1',
                'unit' => $unitCode,
                'unit_price' => $netLine !== null ? (string) $netLine : '0',
            ];
        } while ($reader->nextDocumentPosition());

        return new EInvoiceParseResult(
            formatKey: $this->formatKey(),
            documentNumber: (string) $documentNo,
            issueDate: $documentDate?->format('Y-m-d'),
            currency: $currency,
            grossMinor: $gross,
            netMinor: $net,
            taxMinor: $tax,
            sellerName: $sellerName,
            sellerVatId: is_scalar($vatId) ? (string) $vatId : null,
            lines: $lines,
            originalXml: $contents,
            sha256: $hash,
            valid: true,
            errors: [],
            meta: [
                'filename' => $filename,
                'type_code' => $documentTypeCode,
                'seller_city' => $sellerCity,
                'seller_country' => $sellerCountry,
            ],
        );
    }

    public function generate(array $snapshot): string
    {
        $builder = ZugferdDocumentBuilder::CreateNew(ZugferdProfiles::PROFILE_EN16931);
        $number = (string) ($snapshot['number'] ?? 'DRAFT');
        $issueDate = new \DateTimeImmutable((string) ($snapshot['issue_date'] ?? 'now'));
        $currency = (string) ($snapshot['currency'] ?? 'EUR');

        $builder->setDocumentInformation($number, '380', \DateTime::createFromImmutable($issueDate), $currency);
        $builder->setDocumentSeller((string) ($snapshot['seller_name'] ?? 'Seller'));
        $builder->setDocumentBuyer((string) ($snapshot['buyer_name'] ?? 'Buyer'));

        $position = 1;
        foreach ($snapshot['lines'] ?? [] as $line) {
            $builder->addNewPosition((string) $position);
            $builder->setDocumentPositionProductDetails((string) ($line['description'] ?? 'Item'));
            $quantity = (float) ($line['quantity'] ?? 1);
            $builder->setDocumentPositionQuantity($quantity, (string) ($line['unit'] ?? 'C62'));
            $unitPrice = isset($line['unit_price_minor'])
                ? ExactMoney::ofMinor((int) $line['unit_price_minor'], $currency)->decimalString()
                : (string) ($line['unit_price'] ?? '0');
            $builder->setDocumentPositionNetPrice((float) $unitPrice);
            $position++;
        }

        $gross = ExactMoney::ofMinor((int) ($snapshot['gross_minor'] ?? 0), $currency)->decimalString();
        $net = ExactMoney::ofMinor((int) ($snapshot['net_minor'] ?? 0), $currency)->decimalString();
        $tax = ExactMoney::ofMinor((int) ($snapshot['tax_minor'] ?? 0), $currency)->decimalString();
        $builder->setDocumentSummation((float) $gross, (float) $gross, (float) $net, 0.0, 0.0, (float) $net, (float) $tax);

        return $builder->getContent();
    }

    private function toMinor(mixed $amount, string $currency): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return ExactMoney::ofString((string) $amount, $currency)->minorAmount;
    }
}
