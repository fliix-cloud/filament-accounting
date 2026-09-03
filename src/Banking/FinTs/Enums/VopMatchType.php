<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

use Fhp\Model\VopVerificationResult;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VopMatchType: string implements HasColor, HasLabel
{
    case FullMatch = 'full_match';
    case CloseMatch = 'close_match';
    case PartialMatch = 'partial_match';
    case NoMatch = 'no_match';
    case NotApplicable = 'not_applicable';
    case Unknown = 'unknown';

    public static function fromBankResult(?string $result): self
    {
        return match ($result) {
            VopVerificationResult::CompletedFullMatch => self::FullMatch,
            VopVerificationResult::CompletedCloseMatch => self::CloseMatch,
            VopVerificationResult::CompletedPartialMatch => self::PartialMatch,
            VopVerificationResult::CompletedNoMatch => self::NoMatch,
            VopVerificationResult::NotApplicable => self::NotApplicable,
            default => self::Unknown,
        };
    }

    public function getLabel(): string
    {
        return __('filament-accounting::banking/fints/statuses.vop.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FullMatch => 'success',
            self::CloseMatch, self::PartialMatch, self::NotApplicable, self::Unknown => 'warning',
            self::NoMatch => 'danger',
        };
    }

    public function requiresExplicitConfirmation(): bool
    {
        return $this !== self::FullMatch;
    }
}
