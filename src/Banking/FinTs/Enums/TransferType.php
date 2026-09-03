<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransferType: string implements HasLabel
{
    case Sepa = 'sepa';
    case Realtime = 'realtime';
    case International = 'international';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.transfer_type.'.$this->value);
    }
}
