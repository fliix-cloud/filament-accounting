<?php

namespace FilamentAccounting\Documents\Data;

final readonly class EInvoiceParseResult
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $formatKey,
        public string $documentNumber,
        public ?string $issueDate,
        public string $currency,
        public int $grossMinor,
        public int $netMinor,
        public int $taxMinor,
        public ?string $sellerName,
        public ?string $sellerVatId,
        public array $lines,
        public string $originalXml,
        public string $sha256,
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
        public array $meta = [],
    ) {}
}
