<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScaSessionState: string implements HasColor, HasLabel
{
    case NeedsTan = 'needs_tan';
    case NeedsDecoupled = 'needs_decoupled';
    case NeedsVop = 'needs_vop';
    case NeedsPolling = 'needs_polling';
    case Done = 'done';
    case Failed = 'failed';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.sca.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Failed, self::Expired => 'danger',
            default => 'warning',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [
            self::NeedsTan,
            self::NeedsDecoupled,
            self::NeedsVop,
            self::NeedsPolling,
        ], true);
    }
}
