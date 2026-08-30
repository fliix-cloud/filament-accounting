<?php

namespace FilamentAccounting\Contracts;

interface BankFeedDriverRegistry
{
    public function register(BankFeedDriver $driver): void;

    public function get(string $key): BankFeedDriver;

    /**
     * @return array<string, BankFeedDriver>
     */
    public function all(): array;
}
