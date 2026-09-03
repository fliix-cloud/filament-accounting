<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Models\AccountingBankAccount;

/**
 * @deprecated Use UnifiedBankTransactionImporter. Kept as a source-compatible
 *             façade for pre-0.1 integrations during the consolidation window.
 */
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
