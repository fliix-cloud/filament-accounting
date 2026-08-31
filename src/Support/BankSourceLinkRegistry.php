<?php

namespace FilamentAccounting\Support;

use FilamentAccounting\Contracts\BankSourceLinkGenerator;
use FilamentAccounting\Models\BankStatementLine;

final class BankSourceLinkRegistry
{
    /** @var array<string, BankSourceLinkGenerator> */
    private array $generators = [];

    public function register(BankSourceLinkGenerator $generator): void
    {
        $this->generators[$generator->driverKey()] = $generator;
    }

    public function url(BankStatementLine $line): ?string
    {
        $generator = $this->generators[$line->driver_key] ?? null;

        return $generator?->url($line);
    }
}
