<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Received = 'received';
    case Corrected = 'corrected';
    case Cancelled = 'cancelled';
    case Discarded = 'discarded';

    public function getLabel(): string
    {
        return __('filament-accounting::statuses.document.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued, self::Received => 'success',
            self::Corrected => 'warning',
            self::Cancelled, self::Discarded => 'danger',
        };
    }
}
