<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Models\BankConnection;
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
                    $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
                    $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;
                    $transactions->sync($account, $from, $to);
                }
            }
        }

        $this->info('Synchronization finished.');

        return self::SUCCESS;
    }
}
