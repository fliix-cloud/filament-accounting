<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BankConnectionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case NeedsAttention = 'needs_attention';
    case Error = 'error';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.connection.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::NeedsAttention => 'warning',
            self::Error => 'danger',
            self::Disabled => 'gray',
        };
    }
}
