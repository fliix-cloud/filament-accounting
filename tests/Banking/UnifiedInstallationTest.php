<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class UnifiedInstallationTest extends TestCase
{
    #[Test]
    public function a_fresh_install_has_one_canonical_bank_account_and_transaction_schema(): void
    {
        foreach ([
            'accounting_bank_accounts',
            'accounting_bank_statement_lines',
            'accounting_bank_transaction_source_versions',
            'fints_bank_connections',
            'fints_bank_transfers',
            'fints_bank_direct_debits',
            'fints_direct_debit_mandates',
            'fints_sca_sessions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing unified table {$table}.");
        }

        $this->assertFalse(Schema::hasTable('fints_bank_accounts'));
        $this->assertFalse(Schema::hasTable('fints_bank_transactions'));
    }

    #[Test]
    public function an_accounting_only_database_is_extended_without_losing_its_accounts(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());

        Schema::disableForeignKeyConstraints();
        foreach ([
            'fints_sync_runs',
            'fints_sca_sessions',
            'fints_bank_direct_debits',
            'fints_bank_transfers',
            'fints_direct_debit_mandates',
            'fints_direct_debit_creditor_profiles',
            'fints_bank_connections',
            'accounting_bank_transaction_source_versions',
            'accounting_legacy_consolidation_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000010_unify_fints_banking.php';
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->up();

        $this->assertTrue(AccountingBankAccount::query()->whereKey($account->getKey())->exists());
        $this->assertTrue(Schema::hasTable('fints_bank_connections'));
        $this->assertTrue(Schema::hasTable('accounting_bank_transaction_source_versions'));
        $this->assertFalse(Schema::hasTable('fints_bank_accounts'));
        $this->assertFalse(Schema::hasTable('fints_bank_transactions'));
    }
}
