<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class UnsupportedCapabilityException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.unsupported_capability');
    }
}
