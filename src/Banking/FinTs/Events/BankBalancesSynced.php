<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankBalancesSynced
{
    public function __construct(
        public readonly int $bankConnectionId,
        public readonly int $bankAccountId,
    ) {}
}
