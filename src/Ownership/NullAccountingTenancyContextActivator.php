<?php

namespace FilamentAccounting\Ownership;

use FilamentAccounting\Contracts\AccountingTenancyContextActivator;

final class NullAccountingTenancyContextActivator implements AccountingTenancyContextActivator
{
    public function activate(?string $ownerType, ?string $ownerId): void
    {
        // Host applications bind a tenancy activator when using multi-database tenancy.
    }
}
