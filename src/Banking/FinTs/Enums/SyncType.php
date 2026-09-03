<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum SyncType: string implements HasLabel
{
    case Accounts = 'accounts';
    case Balances = 'balances';
    case Transactions = 'transactions';
    case All = 'all';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.sync.'.$this->value);
    }
}
