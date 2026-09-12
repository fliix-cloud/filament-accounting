<?php

namespace FilamentAccounting\Tests\Integration;

use Fhp\Model\StatementOfAccount\Statement;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction as FhpTransaction;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
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

    #[Test]
    public function a_long_gap_drains_oldest_first_across_chunks_with_idempotent_imports_on_mysql(): void
    {
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $account = $this->syncAccount($this->makeEntity());
        $account->catch_up_from = Carbon::today()->subDays(200);
        $account->save();
        $svc = app(TransactionSyncService::class);

        // Drain the gap the way repeated sync() calls would: one chunk per loop,
        // each window imported once, the marker advancing and finally clearing.
        [$dates, $chunks] = [['-150', '-60', '-5'], []];
        $steps = 0;
        $marker = $account->catch_up_from;
        while ($marker instanceof Carbon && $steps < 10) {
            [$from, $to, $next] = $svc->boundedRange($marker, Carbon::today());
            $statement = new TestStatementOfAccount;
            $day = new Statement;
            $day->setDate(new \DateTime(Carbon::now()->addDays((int) $dates[$steps])->toDateString()));
            $day->addTransaction($this->transaction((int) $dates[$steps]));
            $statement->push($day);

            $count = $svc->importStatement($account, $statement);
            $run = new BankSyncRun([
                'bank_connection_id' => $account->bank_connection_id,
                'accounting_bank_account_id' => $account->id,
                'legal_entity_id' => $account->legal_entity_id,
                'type' => SyncType::Transactions,
                'status' => SyncStatus::Completed,
                'from_date' => $from,
                'to_date' => $to,
                'requested_from_date' => $next,
                'item_count' => $count,
                'started_at' => now(),
                'finished_at' => now(),
            ]);
            $run->save();
            $svc->markSyncCompleted($account, $run, ['imported' => $count, 'updated' => 0]);
            $chunks[] = $count;
            $marker = $next;
            $steps++;
        }

        // Three chunks drained a 200-day gap at a 90-day window, each importing
        // exactly one distinct transaction, and the marker cleared.
        $this->assertSame(3, $steps);
        $this->assertNull($marker);
        $this->assertNull($account->fresh()?->catch_up_from);
        $this->assertSame([1, 1, 1], $chunks);
        $this->assertSame(3, BankStatementLine::query()->where('bank_account_id', $account->id)->count());
        $this->assertNotNull($account->fresh()?->last_transaction_sync_at);
    }

    private function syncAccount(LegalEntity $entity): AccountingBankAccount
    {
        $connection = BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Testbank',
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
            'status' => BankConnectionStatus::Active,
        ]);

        return AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'bank_connection_id' => $connection->getKey(),
            'display_name' => 'Geschäftskonto',
            'external_account_id' => 'account-'.$connection->getKey(),
            'fingerprint' => 'fingerprint-'.$connection->getKey(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'account_number' => '0532013000',
            'bank_code' => '37040044',
            'currency' => 'EUR',
            'account_holder_name' => 'Demo GmbH',
            'is_available' => true,
            'is_enabled' => true,
        ]);
    }

    private function transaction(int $seed): FhpTransaction
    {
        $transaction = new FhpTransaction;
        $transaction->setBookingDate(new \DateTime(Carbon::now()->addDays($seed)->toDateString()));
        $transaction->setValutaDate(new \DateTime(Carbon::now()->addDays($seed)->toDateString()));
        $transaction->setAmount(12.34);
        $transaction->setCreditDebit(FhpTransaction::CD_CREDIT);
        $transaction->setIsStorno(false);
        $transaction->setBookingCode('NTRF');
        $transaction->setBookingText('GUTSCHRIFT');
        $transaction->setDescription1('Drain '.$seed);
        $transaction->setDescription2('');
        $transaction->setStructuredDescription(['SVWZ' => 'Invoice '.$seed]);
        $transaction->setBankCode('37040044');
        $transaction->setAccountNumber('123456');
        $transaction->setName('Acme GmbH');
        $transaction->setBooked(true);
        $transaction->setPN(1);
        $transaction->setTextKeyAddition(0);

        return $transaction;
    }
}

final class TestStatementOfAccount extends StatementOfAccount
{
    public function push(Statement $statement): self
    {
        $this->statements[] = $statement;

        return $this;
    }
}
