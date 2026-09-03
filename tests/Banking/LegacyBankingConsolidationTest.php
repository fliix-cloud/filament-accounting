<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\Services\LegacyBankingConsolidator;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\BankTransactionSourceVersion;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyBankingConsolidationTest extends TestCase
{
    public function test_dry_run_and_apply_preserve_legacy_tables_and_are_idempotent(): void
    {
        $entity = $this->makeEntity();
        $this->installLegacyBankingSchema();

        DB::table('fints_bank_connections')->insert([
            'id' => 11,
            'uuid' => '10000000-0000-4000-8000-000000000011',
            'owner_type' => $entity->getMorphClass(),
            'owner_id' => (string) $entity->getKey(),
            'display_name' => 'Legacy Bank',
            'bank_code' => '37040044',
            'endpoint_url' => 'https://bank.example/fints',
            'username' => 'encrypted-user',
            'pin' => 'encrypted-pin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fints_bank_accounts')->insert([
            'id' => 21,
            'uuid' => '20000000-0000-4000-8000-000000000021',
            'bank_connection_id' => 11,
            'fingerprint' => 'legacy-account-fingerprint',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'alias' => 'Legacy Giro',
            'currency' => 'EUR',
            'is_active' => true,
            'booked_balance' => '123.45',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fints_bank_transactions')->insert([
            'id' => 31,
            'uuid' => '30000000-0000-4000-8000-000000000031',
            'bank_connection_id' => 11,
            'bank_account_id' => 21,
            'fingerprint' => 'legacy-transaction-fingerprint',
            'occurrence' => 1,
            'booking_date' => '2026-09-01',
            'value_date' => '2026-09-01',
            'amount' => '123.45',
            'currency' => 'EUR',
            'direction' => 'credit',
            'is_booked' => true,
            'is_storno' => false,
            'counterparty_name' => 'Legacy Customer',
            'counterparty_account_number' => 'DE12500105170648489890',
            'purpose' => 'Invoice RE-2026-1',
            'structured_description' => json_encode(['EREF' => 'RE-2026-1'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUnifiedMigration();
        $service = app(LegacyBankingConsolidator::class);
        $dryRun = $service->analyze();
        $repeatedDryRun = $service->analyze();

        $this->assertSame('dry-run', $dryRun['mode']);
        $this->assertSame([], $dryRun['blockers']);
        $this->assertSame(1, $dryRun['expected_targets']['bank_accounts']);
        $this->assertSame(1, $dryRun['expected_targets']['bank_transactions']);
        $this->assertSame($dryRun, $repeatedDryRun);

        $first = $service->consolidate();
        $second = $service->consolidate();

        $account = AccountingBankAccount::query()->where('legacy_fints_bank_account_id', 21)->firstOrFail();
        $line = BankStatementLine::query()->where('external_id', '30000000-0000-4000-8000-000000000031')->firstOrFail();

        $this->assertSame($entity->getKey(), $account->legal_entity_id);
        $this->assertSame(12345, $account->booked_balance_minor);
        $this->assertTrue((int) $account->ledgerAccount->code >= 1201 && (int) $account->ledgerAccount->code <= 1299);
        $this->assertSame(12345, $line->amount_minor);
        $this->assertSame(1, AccountingBankAccount::query()->where('legacy_fints_bank_account_id', 21)->count());
        $this->assertSame(1, BankStatementLine::query()->where('external_id', $line->external_id)->count());
        $this->assertSame(1, BankTransactionSourceVersion::query()->where('bank_transaction_id', $line->getKey())->count());
        $this->assertSame('apply', $first['mode']);
        $this->assertSame('apply', $second['mode']);
        $this->assertTrue($first['validation']['passed']);
        $this->assertTrue($second['validation']['passed']);
        $this->assertSame(['EUR' => 12345], $first['validation']['amounts_minor_by_currency']['expected']);
        $this->assertSame(
            $first['validation']['amounts_minor_by_currency']['expected'],
            $first['validation']['amounts_minor_by_currency']['actual'],
        );
        $this->assertSame(
            $first['validation']['source_hashes']['expected'],
            $first['validation']['source_hashes']['actual'],
        );
        $this->assertSame(1, $first['validation']['source_hashes']['preserved_source_versions']);
        $this->assertTrue(Schema::hasTable('fints_bank_accounts'));
        $this->assertTrue(Schema::hasTable('fints_bank_transactions'));
        $this->assertSame('completed-read-only', DB::table('accounting_legacy_consolidation_runs')->latest('id')->value('status'));
    }

    public function test_unknown_owner_mapping_blocks_dry_run_and_apply(): void
    {
        $this->makeEntity();
        $this->installLegacyBankingSchema();

        DB::table('fints_bank_connections')->insert([
            'id' => 12,
            'uuid' => '10000000-0000-4000-8000-000000000012',
            'owner_type' => 'App\\Models\\UnknownTenant',
            'owner_id' => '999',
            'display_name' => 'Unmapped Bank',
            'bank_code' => '37040044',
            'endpoint_url' => 'https://bank.example/fints',
            'username' => 'encrypted-user',
            'pin' => 'encrypted-pin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUnifiedMigration();
        $service = app(LegacyBankingConsolidator::class);
        $report = $service->analyze();

        $this->assertSame('owner_mapping_missing_or_ambiguous', $report['blockers'][0]['reason']);
        $this->expectException(\DomainException::class);
        $service->consolidate();
    }

    private function installLegacyBankingSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'fints_sync_runs',
            'fints_sca_sessions',
            'fints_bank_direct_debits',
            'fints_bank_transfers',
            'fints_direct_debit_mandates',
            'fints_direct_debit_creditor_profiles',
            'fints_bank_accounts',
            'fints_bank_connections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        Schema::create('fints_bank_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('display_name');
            $table->string('bank_code', 16);
            $table->string('endpoint_url');
            $table->text('username');
            $table->text('pin');
            $table->string('status', 32)->default('pending');
            $table->timestamps();
        });
        Schema::create('fints_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('bank_connection_id');
            $table->string('fingerprint', 128);
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('alias')->nullable();
            $table->string('product_name')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->decimal('booked_balance', 18, 2)->nullable();
            $table->decimal('pending_balance', 18, 2)->nullable();
            $table->decimal('credit_line', 18, 2)->nullable();
            $table->decimal('available_amount', 18, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('fints_bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('bank_connection_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->string('fingerprint', 128);
            $table->unsignedInteger('occurrence')->default(1);
            $table->date('booking_date')->nullable();
            $table->date('value_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('direction', 16);
            $table->boolean('is_booked')->default(true);
            $table->boolean('is_storno')->default(false);
            $table->string('booking_code')->nullable();
            $table->string('booking_text')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account_number')->nullable();
            $table->text('purpose')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->json('structured_description')->nullable();
            $table->timestamps();
        });
    }

    private function runUnifiedMigration(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000010_unify_fints_banking.php';
        $this->assertInstanceOf(Migration::class, $migration);
        $migration->up();
    }
}
