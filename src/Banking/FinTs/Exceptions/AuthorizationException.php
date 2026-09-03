<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class AuthorizationException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.unauthorized');
    }
}
