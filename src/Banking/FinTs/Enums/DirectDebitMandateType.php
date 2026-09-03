<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum DirectDebitMandateType: string implements HasLabel
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.mandate_type.'.$this->value);
    }
}
