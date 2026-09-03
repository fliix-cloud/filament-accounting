<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class AuthenticationException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.authentication');
    }
}
