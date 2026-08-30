<?php

namespace FilamentAccounting\Enums;

enum DocumentType: string
{
    case SalesInvoice = 'sales_invoice';
    case PurchaseInvoice = 'purchase_invoice';
    case SalesCreditNote = 'sales_credit_note';
    case PurchaseCreditNote = 'purchase_credit_note';

    public function direction(): DocumentDirection
    {
        return match ($this) {
            self::SalesInvoice, self::SalesCreditNote => DocumentDirection::Outgoing,
            self::PurchaseInvoice, self::PurchaseCreditNote => DocumentDirection::Incoming,
        };
    }

    public function isCreditNote(): bool
    {
        return $this === self::SalesCreditNote || $this === self::PurchaseCreditNote;
    }
}
