<?php

namespace FilamentAccounting\Authorization;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Exceptions\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class DefaultAccountingAuthorizer implements AccountingAuthorizer
{
    public function __construct(
        private readonly AccountingActorResolver $actors,
    ) {}

    public function can(string $ability, mixed $subject = null): bool
    {
        $actor = $this->actors->resolve();
        $gate = $this->gateName($ability);

        if (Gate::has($gate)) {
            return Gate::forUser($actor)->allows($gate, $subject);
        }

        return $actor !== null;
    }

    public function authorize(string $ability, mixed $subject = null): void
    {
        if (! $this->can($ability, $subject)) {
            throw new AuthorizationException(__('filament-accounting::errors.unauthorized', ['ability' => $ability]));
        }
    }

    private function gateName(string $ability): string
    {
        $mapped = config("filament-accounting.authorization.abilities.{$ability}");

        return is_string($mapped) && $mapped !== '' ? $mapped : $ability;
    }
}
