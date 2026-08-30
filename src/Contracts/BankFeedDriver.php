<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Models\AccountingBankAccount;

interface BankFeedDriver
{
    public function key(): string;

    /**
     * @return list<BankStatementLineData>
     */
    public function fetchChangedLines(AccountingBankAccount $account, ?string $cursor, ?string $fromDate, ?string $toDate): array;

    public function import(AccountingBankAccount $account, ?string $cursor = null): BankFeedImportResult;
}
