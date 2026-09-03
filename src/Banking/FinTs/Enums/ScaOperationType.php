<?php

namespace FilamentAccounting\Banking\FinTs\Enums;

enum ScaOperationType: string
{
    case Login = 'login';
    case TestConnection = 'test_connection';
    case DiscoverTan = 'discover_tan';
    case SyncAccounts = 'sync_accounts';
    case SyncBalances = 'sync_balances';
    case SyncTransactions = 'sync_transactions';
    case Transfer = 'transfer';
    case DirectDebit = 'direct_debit';
}
