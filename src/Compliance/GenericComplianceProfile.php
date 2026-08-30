<?php

namespace FilamentAccounting\Compliance;

use FilamentAccounting\Contracts\ComplianceProfile;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\SeedChartAndRoles;

final class GenericComplianceProfile implements ComplianceProfile
{
    public function __construct(
        private readonly SeedChartAndRoles $chart,
    ) {}

    public function key(): string
    {
        return 'generic';
    }

    public function seed(LegalEntity $entity): void
    {
        $this->chart->handle($entity);
    }

    public function taxTiming(): string
    {
        return 'accrual';
    }
}
