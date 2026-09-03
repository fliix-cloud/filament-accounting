<?php

namespace FilamentAccounting\Banking\FinTs\Policies;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use Illuminate\Database\Eloquent\Model;

class BankConnectionPolicy
{
    public function __construct(
        private readonly BankAuthorizer $authorizer,
    ) {}

    public function viewAny(?Model $actor): bool
    {
        return $this->authorizer->can('view_bank');
    }

    public function view(?Model $actor, BankConnection $connection): bool
    {
        return $this->authorizer->can('view_bank', $connection);
    }

    public function create(?Model $actor): bool
    {
        return $this->authorizer->can('manage_bank_connections');
    }

    public function update(?Model $actor, BankConnection $connection): bool
    {
        return $this->authorizer->can('manage_bank_connections', $connection);
    }

    public function delete(?Model $actor, BankConnection $connection): bool
    {
        return $this->authorizer->can('manage_bank_connections', $connection);
    }
}
