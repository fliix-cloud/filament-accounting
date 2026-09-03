<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

use RuntimeException;

class FinTsException extends RuntimeException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.generic');
    }
}
