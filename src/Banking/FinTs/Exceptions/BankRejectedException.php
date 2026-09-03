<?php

namespace FilamentAccounting\Banking\FinTs\Exceptions;

class BankRejectedException extends FinTsException
{
    public function __construct(
        string $message = '',
        public readonly ?string $bankMessage = null,
        public readonly ?string $bankCode = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function userMessage(): string
    {
        return __('filament-accounting::banking/fints/errors.bank_rejected');
    }
}
