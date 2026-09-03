<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankTransferSubmitted
{
    public function __construct(
        public readonly string $transferUuid,
        public readonly int $bankConnectionId,
    ) {}
}
