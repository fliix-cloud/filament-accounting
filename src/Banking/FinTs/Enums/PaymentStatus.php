<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Initiating = 'initiating';
    case AwaitingVop = 'awaiting_vop';
    case AwaitingTan = 'awaiting_tan';
    case AwaitingDecoupledConfirmation = 'awaiting_decoupled_confirmation';
    case WaitingBank = 'waiting_bank';
    case Submitted = 'submitted';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Ambiguous = 'ambiguous';

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.payment.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Initiating, self::WaitingBank => 'info',
            self::AwaitingVop, self::AwaitingTan, self::AwaitingDecoupledConfirmation => 'warning',
            self::Submitted => 'success',
            self::Failed, self::Expired => 'danger',
            self::Cancelled, self::Ambiguous => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::Failed,
            self::Expired,
            self::Cancelled,
            self::Ambiguous,
        ], true);
    }

    public function isInteractive(): bool
    {
        return in_array($this, [
            self::AwaitingVop,
            self::AwaitingTan,
            self::AwaitingDecoupledConfirmation,
            self::WaitingBank,
        ], true);
    }

    /**
     * Drafts never left this app. Failed payments were rejected or never
     * accepted. Submitted or ambiguous records may already exist at the bank.
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft || $this === self::Failed;
    }
}
