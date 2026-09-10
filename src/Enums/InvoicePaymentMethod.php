<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoicePaymentMethod: string implements HasLabel
{
    case CreditTransfer = 'credit_transfer';
    case DirectDebit = 'direct_debit';

    public function getLabel(): string
    {
        return __('filament-accounting::invoice.payment_methods.'.$this->value);
    }
}
