<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum PartyAddressRole: string implements HasLabel
{
    case Billing = 'billing';
    case Shipping = 'shipping';
    case Both = 'both';

    public function getLabel(): string
    {
        return __('filament-accounting::fields.address_roles.'.$this->value);
    }
}
