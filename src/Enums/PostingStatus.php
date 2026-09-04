<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PostingStatus: string implements HasColor, HasLabel
{
    case Unposted = 'unposted';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function getLabel(): string
    {
        return __('filament-accounting::statuses.posting.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unposted => 'gray',
            self::Posted => 'success',
            self::Reversed => 'danger',
        };
    }
}
