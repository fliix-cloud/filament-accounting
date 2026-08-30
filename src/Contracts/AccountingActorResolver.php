<?php

namespace FilamentAccounting\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AccountingActorResolver
{
    public function resolve(): ?Model;
}
