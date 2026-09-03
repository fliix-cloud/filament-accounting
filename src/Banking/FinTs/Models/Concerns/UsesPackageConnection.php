<?php

namespace FilamentAccounting\Banking\FinTs\Models\Concerns;

trait UsesPackageConnection
{
    public function getConnectionName()
    {
        return config('filament-accounting.database.connection') ?: parent::getConnectionName();
    }
}
