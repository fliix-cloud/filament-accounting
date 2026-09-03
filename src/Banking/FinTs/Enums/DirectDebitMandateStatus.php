<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasLabel;

enum DirectDebitMandateStatus: string implements HasLabel
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.mandate_status.'.$this->value);
    }
}
