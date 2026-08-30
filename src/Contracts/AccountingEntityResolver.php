<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Models\LegalEntity;

interface AccountingEntityResolver
{
    public function resolve(): ?LegalEntity;
}
