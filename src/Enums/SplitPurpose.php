<?php

namespace FilamentAccounting\Enums;

enum SplitPurpose: string
{
    case SettleOpenItem = 'settle_open_item';
    case PostingRule = 'posting_rule';
    case LedgerAccount = 'ledger_account';
    case BankFee = 'bank_fee';
    case Transfer = 'transfer';
    case Suspense = 'suspense';
    case Rounding = 'rounding';
}
