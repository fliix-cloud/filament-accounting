<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\BalanceSyncService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncCommand extends Command
{
    protected $signature = 'filament-accounting:sync-bank
        {--connection= : Bank connection UUID}
        {--account= : Bank account UUID}
        {--accounts : Sync accounts}
        {--balances : Sync balances}
        {--transactions : Sync transactions}
        {--from= : Statement start date Y-m-d}
        {--to= : Statement end date Y-m-d}';

    protected $description = 'Synchronize FinTS accounts, balances, or transactions without interactive payments';

    public function handle(
        AccountSyncService $accounts,
        BalanceSyncService $balances,
        TransactionSyncService $transactions,
    ): int {
        if (! ProductRegistration::isConfigured()) {
            $this->error(__('filament-accounting::banking/fints/notifications.product_id_missing'));

            return self::FAILURE;
        }

        $doAccounts = $this->option('accounts') || (! $this->option('balances') && ! $this->option('transactions'));
        $doBalances = (bool) $this->option('balances');
        $doTransactions = (bool) $this->option('transactions');

        $query = BankConnection::query();
        if ($uuid = $this->option('connection')) {
            $query->where('uuid', $uuid);
        }

        $requestedFrom = $this->option('from');

        foreach ($query->get() as $connection) {
            if ($doAccounts) {
                $outcome = $accounts->sync($connection);
                if ($outcome->requiresUser()) {
                    $this->warn("Connection {$connection->uuid} needs SCA attention.");
                }
            }

            $accountQuery = $connection->accounts()
                ->where('is_available', true)
                ->where('is_enabled', true);
            if ($accountUuid = $this->option('account')) {
                $accountQuery->where('uuid', $accountUuid);
            }

            foreach ($accountQuery->get() as $account) {
                if (! $account instanceof BankAccount) {
                    continue;
                }
                if ($doBalances) {
                    $balances->sync($account);
                }

                if ($doTransactions) {
                    $from = $requestedFrom ? Carbon::parse($requestedFrom) : null;
                    $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;
                    $transactions->sync($account, $from, $to);
                    $this->warnIfTruncated((int) $account->getKey());
                }
            }
        }

        $this->info('Synchronization finished.');

        return self::SUCCESS;
    }

    /**
     * A sync that silently drops the requested range would look successful while
     * omitting bookings. Surface the recorded gap so operators do not infer
     * completeness from a green run.
     *
     * The service now persists the gap as {@see AccountingBankAccount::$catch_up_from}
     * and resumes automatically on the next sync. This warning confirms the
     * chunked progress and the remaining gap.
     */
    private function warnIfTruncated(int $accountId): void
    {
        $account = BankAccount::query()->find($accountId);

        if (! $account instanceof BankAccount) {
            return;
        }

        $run = BankSyncRun::query()
            ->where('accounting_bank_account_id', $accountId)
            ->latest('id')
            ->first();

        // requestFrom carries the still-uncovered frontier (oldest-first drain),
        // so a non-null value means more chunks remain for this account.
        if ($run instanceof BankSyncRun && $run->requested_from_date !== null) {
            $this->warn(sprintf(
                'Account %d: only %s to %s synchronized; coverage gap remains from %s.',
                $accountId,
                $run->from_date?->toDateString() ?? 'unknown',
                $run->to_date?->toDateString() ?? 'unknown',
                $run->requested_from_date->toDateString(),
            ));

            if ($account->catch_up_from instanceof \DateTimeInterface) {
                $remainingDays = $account->catch_up_from->diffInDays(Carbon::today());
                $chunks = (int) ceil($remainingDays / (int) config('filament-accounting.banking.fints.sync.max_range_days', 90));
                $this->warn(sprintf(
                    '  Catch-up gap: %s → today (%d days). Approximately %d chunk(s) remaining; run this command again to drain them.',
                    $account->catch_up_from->toDateString(),
                    $remainingDays,
                    max(1, $chunks),
                ));
            }
        }
    }
}
