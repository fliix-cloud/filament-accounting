<?php

namespace FilamentAccounting\Support;

use FilamentAccounting\Contracts\BankFeedDriver;
use FilamentAccounting\Contracts\BankFeedDriverRegistry;
use InvalidArgumentException;

final class BankFeedRegistry implements BankFeedDriverRegistry
{
    /** @var array<string, BankFeedDriver> */
    private array $drivers = [];

    public function register(BankFeedDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

    public function get(string $key): BankFeedDriver
    {
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("Unknown bank-feed driver [{$key}].");
        }

        return $this->drivers[$key];
    }

    public function all(): array
    {
        return $this->drivers;
    }
}
