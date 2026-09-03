<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankTransferFailed
{
    public function __construct(
        public readonly string $transferUuid,
        public readonly int $bankConnectionId,
        public readonly string $safeMessage,
    ) {}
}
