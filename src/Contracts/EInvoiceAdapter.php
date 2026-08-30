<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Documents\Data\EInvoiceParseResult;

interface EInvoiceAdapter
{
    public function formatKey(): string;

    public function supports(string $mimeType, string $contents): bool;

    public function parse(string $contents, string $filename): EInvoiceParseResult;

    public function generate(array $snapshot): string;
}
