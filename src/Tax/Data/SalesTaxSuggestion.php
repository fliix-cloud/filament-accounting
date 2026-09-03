<?php

namespace FilamentAccounting\Tax\Data;

final readonly class SalesTaxSuggestion
{
    public function __construct(
        public string $taxCode,
        public int $rateBp,
        public string $explanation,
        public bool $requiresConfirmation,
    ) {}
}
