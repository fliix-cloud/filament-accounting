<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankAccountsSynced
{
    public function __construct(
        public readonly int $bankConnectionId,
        public readonly int $count,
    ) {}
}
