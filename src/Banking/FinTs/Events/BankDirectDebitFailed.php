<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankDirectDebitFailed
{
    public function __construct(
        public readonly string $directDebitUuid,
        public readonly int $bankConnectionId,
        public readonly string $safeMessage,
    ) {}
}
