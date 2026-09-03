<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class NetworkException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.network');
    }
}
