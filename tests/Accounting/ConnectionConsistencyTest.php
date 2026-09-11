<?php

namespace FilamentAccounting\Tests\Accounting;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\BankLedgerAccountProvisioner;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Catalog\ImportExport\CatalogImporter;
use FilamentAccounting\Catalog\ImportExport\CatalogSerializer;
use FilamentAccounting\Catalog\ImportExport\CatalogTransferSchema;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ConnectionConsistencyTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function bank_ledger_account_provisioner_uses_accounting_connection(): void
    {
        $entity = $this->fixtureEntity();
        $before = LedgerAccount::query()->count();
        $defaultBefore = $this->defaultConnectionCount('accounting_ledger_accounts');

        app(BankLedgerAccountProvisioner::class)->provision($entity, 'DE89370400440532013000', 'Test Bank', 'EUR');

        $this->assertSame($before + 1, LedgerAccount::query()->count());
        // The default connection must not receive the accounting record.
        $this->assertSame($defaultBefore, $this->defaultConnectionCount('accounting_ledger_accounts'));
        $this->assertSame(0, $defaultBefore);
    }

    #[Test]
    public function bank_ledger_account_provisioner_rolls_back_on_injected_failure(): void
    {
        $entity = $this->fixtureEntity();
        $accountsBefore = LedgerAccount::query()->count();
        $auditBefore = AuditEvent::query()->count();
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->operation === 'bank.ledger-account-provisioned') {
                throw new RuntimeException('Injected provision failure');
            }
        });
        try {
            app(BankLedgerAccountProvisioner::class)->provision($entity, 'DE89370400440532013000', 'Test Bank', 'EUR');
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected provision failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame($accountsBefore, LedgerAccount::query()->count());
        $this->assertSame($auditBefore, AuditEvent::query()->count());
    }

    #[Test]
    public function bank_ledger_account_provisioner_is_idempotent_per_identity(): void
    {
        $entity = $this->fixtureEntity();
        $before = LedgerAccount::query()->count();
        app(BankLedgerAccountProvisioner::class)->provision($entity, 'DE89370400440532013000', 'Test Bank', 'EUR');
        // Same identity returns the existing record instead of creating another.
        app(BankLedgerAccountProvisioner::class)->provision($entity, 'DE89370400440532013000', 'Test Bank', 'EUR');
        $this->assertSame($before + 1, LedgerAccount::query()->count());
        // Different identity provisions a second account.
        app(BankLedgerAccountProvisioner::class)->provision($entity, 'DE02500105170137075030', 'Other Bank', 'EUR');
        $this->assertSame($before + 2, LedgerAccount::query()->count());
    }

    #[Test]
    public function unified_bank_transaction_importer_uses_accounting_connection(): void
    {
        $entity = $this->fixtureEntity();
        $bankAccount = $this->makeBankAccount($entity);
        $this->assertSame(0, BankStatementLine::query()->count());
        $defaultBefore = $this->defaultConnectionCount('accounting_bank_statement_lines');

        app(UnifiedBankTransactionImporter::class)->import($bankAccount, [
            new BankStatementLineData('ext-1', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);

        $this->assertSame(1, BankStatementLine::query()->count());
        $this->assertSame($defaultBefore, $this->defaultConnectionCount('accounting_bank_statement_lines'));
        $this->assertSame(0, $defaultBefore);
    }

    #[Test]
    public function unified_bank_transaction_importer_rolls_back_on_injected_failure(): void
    {
        $entity = $this->fixtureEntity();
        $bankAccount = $this->makeBankAccount($entity);
        $auditBefore = AuditEvent::query()->count();
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->operation === 'bank.transactions-imported') {
                throw new RuntimeException('Injected import failure');
            }
        });
        try {
            app(UnifiedBankTransactionImporter::class)->import($bankAccount, [
                new BankStatementLineData('ext-1', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
            ]);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected import failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, BankStatementLine::query()->count());
        $this->assertSame($auditBefore, AuditEvent::query()->count());
    }

    #[Test]
    public function catalog_importer_uses_accounting_connection(): void
    {
        $this->fixtureEntity();
        $path = $this->catalogFixturePath();
        $this->assertSame(0, CatalogItem::query()->count());
        $defaultBefore = $this->defaultConnectionCount('accounting_catalog_items');

        $result = app(CatalogImporter::class)->import($path, 'json');

        $this->assertSame(1, $result->created);
        $this->assertSame(1, CatalogItem::query()->count());
        $this->assertSame($defaultBefore, $this->defaultConnectionCount('accounting_catalog_items'));
        $this->assertSame(0, $defaultBefore);
    }

    #[Test]
    public function catalog_importer_rolls_back_on_injected_failure(): void
    {
        $this->fixtureEntity();
        $path = $this->catalogFixturePath();
        $fail = true;
        CatalogItem::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Injected catalog save failure');
            }
        });
        try {
            app(CatalogImporter::class)->import($path, 'json');
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected catalog save failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, CatalogItem::query()->count());
    }

    private function fixtureEntity(): LegalEntity
    {
        config()->set('database.connections.consistency_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('consistency_accounting')->getSchemaBuilder());
        try {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
                (require $path)->up();
            }
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'consistency_accounting');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        return $entity;
    }

    private function catalogFixturePath(): string
    {
        $path = sys_get_temp_dir().'/consistency_catalog_'.uniqid().'.json';
        app(CatalogSerializer::class)->write($path, 'json', [CatalogTransferSchema::example()]);

        return $path;
    }

    private function defaultConnectionCount(string $table): int
    {
        return (int) DB::connection('sqlite')->table($table)->count();
    }
}
