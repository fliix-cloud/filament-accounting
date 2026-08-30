<?php

namespace FilamentAccounting\Compliance\Germany;

use FilamentAccounting\Contracts\ComplianceProfile;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\SeedGermanProfile;

final class GermanComplianceProfile implements ComplianceProfile
{
    public function __construct(
        private readonly SeedGermanProfile $seed,
    ) {}

    public function key(): string
    {
        return 'DE';
    }

    public function seed(LegalEntity $entity): void
    {
        $this->seed->handle($entity);
    }

    public function taxTiming(): string
    {
        return 'accrual';
    }
}
