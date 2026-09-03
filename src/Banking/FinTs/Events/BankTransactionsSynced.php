<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankTransactionsSynced
{
    public function __construct(
        public readonly int $bankConnectionId,
        public readonly int $bankAccountId,
        public readonly int $imported,
    ) {}
}
