<?php

namespace FilamentAccounting\Ownership;

use Filament\Facades\Filament;
use FilamentAccounting\Contracts\AccountingActorResolver;
use Illuminate\Database\Eloquent\Model;

final class AuthenticatedUserAccountingActorResolver implements AccountingActorResolver
{
    public function resolve(): ?Model
    {
        try {
            $user = Filament::auth()->user();
            if ($user instanceof Model) {
                return $user;
            }
        } catch (\Throwable) {
            // Filament auth may be unavailable outside a panel.
        }

        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
