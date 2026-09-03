<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankDirectDebitSubmitted
{
    public function __construct(
        public readonly string $directDebitUuid,
        public readonly int $bankConnectionId,
    ) {}
}
