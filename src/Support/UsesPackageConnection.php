<?php

namespace FilamentAccounting\Support;

trait UsesPackageConnection
{
    public function getConnectionName()
    {
        return config('filament-accounting.database.connection') ?: parent::getConnectionName();
    }
}
