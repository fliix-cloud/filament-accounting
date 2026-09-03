<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class AmbiguousSubmissionException extends FinTsException
{
    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.ambiguous');
    }
}
