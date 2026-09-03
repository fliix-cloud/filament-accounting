<?php

namespace FilamentAccounting\Banking\FinTs\Ownership;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * FinTS-specific query helpers over the package-wide LegalEntity boundary.
 *
 * This class deliberately does not resolve a second owner. All identity comes
 * from the canonical accounting LegalEntityScope.
 */
final class LegalEntityBankScope
{
    public function __construct(
        private readonly LegalEntityScope $entities,
    ) {}

    public function current(): ?LegalEntity
    {
        return $this->entities->current();
    }

    public function require(): LegalEntity
    {
        return $this->entities->require();
    }

    /** @return Builder<BankConnection> */
    public function connections(?LegalEntity $entity = null): Builder
    {
        $entity ??= $this->require();

        return BankConnection::query()->where('legal_entity_id', $entity->getKey());
    }

    public function assertMatches(BankConnection $connection, ?LegalEntity $entity = null): void
    {
        $entity ??= $this->require();
        $this->entities->assertSame($connection->legal_entity_id, $entity);
    }
}
