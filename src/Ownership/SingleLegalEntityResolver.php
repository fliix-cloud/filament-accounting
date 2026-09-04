<?php

namespace FilamentAccounting\Ownership;

use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Models\LegalEntity;

final class SingleLegalEntityResolver implements AccountingEntityResolver
{
    private ?LegalEntity $bound = null;

    public function bind(?LegalEntity $entity): void
    {
        $this->bound = $entity;
    }

    public function resolve(): ?LegalEntity
    {
        if ($this->bound instanceof LegalEntity) {
            return $this->bound;
        }

        $entities = LegalEntity::query()->oldest('id')->limit(2)->get();

        if ($entities->count() > 1) {
            throw new \LogicException(__('filament-accounting::errors.multiple_legal_entities'));
        }

        return $entities->first();
    }
}
