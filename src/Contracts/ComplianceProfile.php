<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Models\LegalEntity;

interface ComplianceProfile
{
    public function key(): string;

    public function seed(LegalEntity $entity): void;

    public function taxTiming(): string;
}
