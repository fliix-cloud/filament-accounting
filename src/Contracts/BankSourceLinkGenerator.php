<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Models\BankStatementLine;

interface BankSourceLinkGenerator
{
    public function driverKey(): string;

    public function url(BankStatementLine $line): ?string;
}
