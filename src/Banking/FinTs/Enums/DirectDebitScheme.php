<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum DirectDebitScheme: string implements HasLabel
{
    case Core = 'CORE';
    case B2b = 'B2B';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.scheme.'.$this->value);
    }
}
