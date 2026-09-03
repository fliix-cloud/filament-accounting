<?php

namespace FilamentAccounting\Tests\Banking\FinTs\Fakes;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;

final class FakeFintsClientFactory implements FintsClientFactory
{
    public function __construct(private readonly FakeFintsClient $client) {}

    public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient
    {
        $this->client->rememberPersisted($persistedInstance);

        return $this->client;
    }
}
