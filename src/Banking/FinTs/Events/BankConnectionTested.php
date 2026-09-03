<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class BankConnectionTested
{
    public function __construct(
        public readonly int $bankConnectionId,
        public readonly bool $success,
    ) {}
}
