<?php

namespace FilamentAccounting\Contracts;

interface AccountingAuthorizer
{
    public function can(string $ability, mixed $subject = null): bool;

    public function authorize(string $ability, mixed $subject = null): void;
}
