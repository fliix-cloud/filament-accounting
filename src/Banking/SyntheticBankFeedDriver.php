<?php

namespace FilamentAccounting\Banking;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Contracts\BankFeedDriver;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Services\ImportBankStatementLines;

final class SyntheticBankFeedDriver implements BankFeedDriver
{
    /**
     * @param  list<BankStatementLineData>  $lines
     */
    public function __construct(
        private array $lines = [],
    ) {}

    public function key(): string
    {
        return 'synthetic';
    }

    /**
     * @param  list<BankStatementLineData>  $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = $lines;
    }

    public function fetchChangedLines(AccountingBankAccount $account, ?string $cursor, ?string $fromDate, ?string $toDate): array
    {
        if (! $account->is_active) {
            return [];
        }

        return array_values(array_filter(
            $this->lines,
            function (BankStatementLineData $line) use ($account, $fromDate, $toDate): bool {
                if ($line->driverKey !== $this->key()) {
                    return false;
                }

                if ($fromDate && $line->bookingDate && $line->bookingDate < $fromDate) {
                    return false;
                }

                if ($toDate && $line->bookingDate && $line->bookingDate > $toDate) {
                    return false;
                }

                return $line->sourceAccountExternalId === null
                    || $line->sourceAccountExternalId === $account->external_account_id;
            }
        ));
    }

    public function import(AccountingBankAccount $account, ?string $cursor = null): BankFeedImportResult
    {
        return app(ImportBankStatementLines::class)->handle(
            $account,
            $this->fetchChangedLines($account, $cursor, null, null),
            $cursor,
        );
    }
}
