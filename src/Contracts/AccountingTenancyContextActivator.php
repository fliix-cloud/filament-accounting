<?php

namespace FilamentAccounting\Contracts;

interface AccountingTenancyContextActivator
{
    public function activate(?string $ownerType, ?string $ownerId): void;
}
