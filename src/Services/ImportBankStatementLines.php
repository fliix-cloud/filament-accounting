<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Models\AccountingBankAccount;

/** Application-facing entry point for canonical bank statement imports. */
final class ImportBankStatementLines
{
    public function __construct(
        private readonly UnifiedBankTransactionImporter $importer,
    ) {}

    /** @param list<BankStatementLineData> $lines */
    public function handle(AccountingBankAccount $account, array $lines, ?string $cursor = null): BankFeedImportResult
    {
        return $this->importer->import($account, $lines, $cursor);
    }
}
