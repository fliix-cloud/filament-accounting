<?php

namespace FilamentAccounting\Documents\Data;

use FilamentAccounting\Models\Document;

final readonly class PurchaseInvoiceUploadResult
{
    public function __construct(
        public Document $document,
        public bool $structured,
        public string $format,
        public string $supplierMatch,
        public bool $idempotentRetry = false,
    ) {}
}
