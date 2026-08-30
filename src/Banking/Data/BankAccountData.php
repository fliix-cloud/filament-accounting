<?php

namespace FilamentAccounting\Banking\Data;

final readonly class BankAccountData
{
    public function __construct(
        public string $externalAccountId,
        public string $driverKey,
        public string $displayName,
        public string $currency,
        public ?string $iban = null,
        public ?string $bic = null,
    ) {}
}
