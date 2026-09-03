<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Completed = 'completed';
    case RequiresAttention = 'requires_attention';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.sync_run.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Completed => 'success',
            self::RequiresAttention => 'warning',
            self::Failed => 'danger',
        };
    }
}
