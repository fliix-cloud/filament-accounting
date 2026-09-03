<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum DirectDebitSequenceType: string implements HasLabel
{
    case First = 'FRST';
    case Recurring = 'RCUR';
    case OneOff = 'OOFF';
    case Final = 'FNAL';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.sequence.'.$this->value);
    }
}
