<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class FintsValidationException extends FinTsException
{
    public function userMessage(): string
    {
        return $this->getMessage() !== '' ? $this->getMessage() : __('filament-accounting::banking/fints/errors.validation');
    }
}
