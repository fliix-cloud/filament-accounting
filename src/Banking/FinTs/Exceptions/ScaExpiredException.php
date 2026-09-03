<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class ScaExpiredException extends FinTsException
{
    public function userMessage(): string
    {
        return $this->getMessage() !== '' ? $this->getMessage() : __('filament-accounting::banking/fints/errors.sca_expired');
    }
}
