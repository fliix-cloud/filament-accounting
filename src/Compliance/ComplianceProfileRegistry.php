<?php

namespace FilamentAccounting\Compliance;

use FilamentAccounting\Contracts\ComplianceProfile;
use InvalidArgumentException;

final class ComplianceProfileRegistry
{
    public function get(string $key): ComplianceProfile
    {
        $class = config("filament-accounting.compliance.profiles.{$key}");

        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException("Unknown compliance profile [{$key}].");
        }

        $profile = app($class);

        if (! $profile instanceof ComplianceProfile) {
            throw new InvalidArgumentException("Compliance profile [{$key}] is not a ComplianceProfile.");
        }

        return $profile;
    }
}
