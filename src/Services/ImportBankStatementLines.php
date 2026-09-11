<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\AccountingBankAccount;

/** Application-facing entry point for canonical bank statement imports. */
final class ImportBankStatementLines
{
    public function __construct(
        private readonly UnifiedBankTransactionImporter $importer,
        private readonly AccountingAuthorizer $authorizer,
    ) {}

    /** @param list<BankStatementLineData> $lines */
    public function handle(AccountingBankAccount $account, array $lines, ?string $cursor = null): BankFeedImportResult
    {
        $this->authorizer->authorize('sync_bank', $account);

        return $this->importer->import($account, $lines, $cursor);
    }
}
