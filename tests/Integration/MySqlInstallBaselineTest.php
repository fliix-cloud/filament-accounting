<?php

namespace FilamentAccounting\Tests\Integration;

use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Release baseline: prove a fresh install of every package migration succeeds on
 * MySQL, the target production engine, and that the baseline can bootstrap a
 * German entity, its chart, a bank account, and a posted sales invoice. The
 * ordinary suite covers this on SQLite only; this opt-in check closes the
 * "fresh install on the supported engine" release gate.
 *
 * Run with ACCOUNTING_TEST_MYSQL=1 (root, no password, 127.0.0.1:3306 by
 * default). A uniquely named database is created and dropped by the test.
 */
class MySqlInstallBaselineTest extends TestCase
{
    private ?\PDO $admin = null;

    private string $database = '';

    private bool $ownsDatabase = false;

    protected function setUp(): void
    {
        if (getenv('ACCOUNTING_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Set ACCOUNTING_TEST_MYSQL=1 to run isolated MySQL install tests.');
        }
        $this->database = 'acct_install_'.bin2hex(random_bytes(12));
        $this->admin = new \PDO('mysql:host='.(getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1')
            .';port='.(getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306'),
            getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->ownsDatabase = true;
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.mysql_install', [
            'driver' => 'mysql', 'host' => getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306', 'database' => $this->database,
            'username' => getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('database.default', 'mysql_install');
        $app['config']->set('filament-accounting.database.connection', 'mysql_install');
    }

    protected function refreshTestDatabase(): void {}

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    protected function afterRefreshingDatabase(): void {}

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->ownsDatabase && $this->admin !== null) {
                $this->admin->exec('DROP DATABASE `'.$this->database.'`');
                $this->ownsDatabase = false;
            }
        }
    }

    #[Test]
    public function a_fresh_mysql_install_bootstraps_the_german_profile_and_core_flows(): void
    {
        // Every package migration ran and the full baseline schema is present.
        foreach ([
            'accounting_legal_entities',
            'accounting_ledger_accounts',
            'accounting_posting_rules',
            'accounting_tax_codes',
            'accounting_documents',
            'accounting_journal_entries',
            'accounting_bank_accounts',
            'accounting_purchase_invoice_intakes',
            'accounting_invoice_artifact_sets',
            'fints_bank_connections',
            'fints_sync_runs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing baseline table {$table}.");
        }

        // The baseline is bootable: a German entity, its chart, a bank account,
        // and an issued/posted sales invoice all work on the MySQL connection.
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $party = $this->makeParty($entity);
        $this->makeBankAccount($entity);

        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $party->getKey(),
            'issue_date' => '2026-09-11',
            'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);

        $this->assertSame(1, LegalEntity::query()->count());
        $this->assertTrue($entity->ledgerAccounts()->count() > 0, 'The German chart must be provisioned.');
        $this->assertSame(11900, (int) $invoice->gross_minor);
        $this->assertNotNull($invoice->openItem);
        $this->assertSame(11900, (int) $invoice->openItem->original_minor);
    }
}
