<?php

namespace FilamentAccounting\Ownership;

use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Models\LegalEntity;

final class ConfiguredLegalEntityResolver implements AccountingEntityResolver
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

        $id = config('filament-accounting.ownership.legal_entity_id');
        if (filled($id)) {
            return LegalEntity::query()->find($id);
        }

        $uuid = config('filament-accounting.ownership.legal_entity_uuid');
        if (filled($uuid)) {
            return LegalEntity::query()->where('uuid', $uuid)->first();
        }

        return null;
    }
}
