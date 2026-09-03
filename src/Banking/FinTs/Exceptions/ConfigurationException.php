<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class ConfigurationException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.configuration');
    }
}
