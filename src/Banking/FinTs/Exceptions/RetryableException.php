<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class RetryableException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.retryable');
    }
}
