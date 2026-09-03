<?php

namespace FilamentAccounting\Banking\FinTs\Contracts;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;

interface FintsClientFactory
{
    public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient;
}
