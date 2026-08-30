<?php

namespace FilamentAccounting\Banking\Data;

final readonly class BankStatementLineData
{
    /**
     * @param  array<string, mixed>|null  $sourcePayload
     */
    public function __construct(
        public string $externalId,
        public int $amountMinor,
        public string $currency,
        public string $driverKey,
        public ?string $sourceAccountExternalId = null,
        public ?string $bookingDate = null,
        public ?string $valueDate = null,
        public string $sourceStatus = 'booked',
        public ?string $counterpartyName = null,
        public ?string $counterpartyIban = null,
        public ?string $counterpartyAccount = null,
        public ?string $purpose = null,
        public ?string $endToEndId = null,
        public ?string $paymentReference = null,
        public ?array $sourcePayload = null,
        public ?string $sourceHash = null,
        public ?string $sourceCreatedAt = null,
        public ?string $sourceUpdatedAt = null,
    ) {}
}
