<?php

namespace FilamentAccounting\Tests\Integration;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\Fixtures\User;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/** Opt-in: creates and drops only its own randomly named, disposable databases. */
class MySqlConcurrencyTest extends TestCase
{
    private ?PDO $admin = null;

    private string $database = '';

    private bool $ownsDatabase = false;

    private array $job = [];

    protected function setUp(): void
    {
        if (getenv('ACCOUNTING_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Set ACCOUNTING_TEST_MYSQL=1 to run isolated MySQL process tests.');
        }
        $this->job = json_decode(getenv('ACCOUNTING_TEST_MYSQL_JOB') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        if ($this->name() === 'worker' && $this->job === []) {
            $this->markTestSkipped('Worker is invoked by a parent concurrency test.');
        }
        $this->database = $this->job['database'] ?? 'acct_concurrency_'.bin2hex(random_bytes(12));
        if (! preg_match('/^acct_concurrency_[a-f0-9]{24}$/D', $this->database)) {
            throw new \RuntimeException('Invalid isolated test database name.');
        }
        if ($this->job === []) {
            $this->admin = new PDO('mysql:host='.(getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1')
                .';port='.(getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306'),
                getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->admin->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->ownsDatabase = true;
        }
        parent::setUp();
        if ($this->job === []) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.mysql_concurrency', [
            'driver' => 'mysql', 'host' => getenv('ACCOUNTING_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('ACCOUNTING_TEST_MYSQL_PORT') ?: '3306', 'database' => $this->database,
            'username' => getenv('ACCOUNTING_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('ACCOUNTING_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('database.default', 'mysql_concurrency');
        $app['config']->set('filament-accounting.database.connection', 'mysql_concurrency');
    }

    protected function refreshTestDatabase(): void {}

    protected function defineDatabaseMigrations(): void
    {
        if ($this->job === []) {
            parent::defineDatabaseMigrations();
        }
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

    public static function scenarios(): array
    {
        return [
            'duplicate' => ['duplicate'],
            'competing_payments' => ['competing_payments'],
            'payment_first' => ['payment_first'],
            'correction_first' => ['correction_first'],
        ];
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function competing_operations_wait_for_the_entity_lock_and_preserve_accounting(string $scenario): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $issuer = app(IssueSalesInvoice::class);
        $payload = ['party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]];
        $invoice = $issuer->handle($entity, $payload);
        $draft = in_array($scenario, ['payment_first', 'correction_first'], true)
            ? $issuer->correct($invoice, $payload, 'Concurrent correction') : null;
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('payment-a', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
            new BankStatementLineData('payment-b', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);
        $lines = BankStatementLine::query()->orderBy('id')->get();
        $ipc = tempnam(sys_get_temp_dir(), 'acct-concurrency-');
        $job = ['database' => $this->database, 'ipc' => $ipc, 'user' => $user->id,
            'operation' => $scenario === 'payment_first' ? 'issue' : 'assign', 'document' => $draft?->id,
            'line' => $lines[$scenario === 'competing_payments' ? 1 : 0]->id, 'item' => $invoice->openItem->id];
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', __FILE__, '--filter', '::worker$'],
            dirname(__DIR__, 2), ['ACCOUNTING_TEST_MYSQL_JOB' => json_encode($job, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(60);
        $connection = $entity->getConnection();
        $connection->beginTransaction();
        try {
            LegalEntity::query()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->waitForLock($process, $ipc);
            if ($scenario === 'correction_first') {
                $winner = $issuer->issue($draft);
            } else {
                $winner = app(AssignStatementLine::class)->handle($lines[0], ['purpose' => 'settle_open_item', 'open_item_id' => $invoice->openItem->id]);
            }
            $connection->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode(file_get_contents($ipc), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($scenario === 'duplicate' ? 'ok' : 'rejected', $result['status']);
            if ($scenario === 'duplicate') {
                $this->assertSame($winner->id, $result['id']);
            } else {
                $this->assertSame(__('filament-accounting::errors.'.($scenario === 'payment_first'
                    ? 'invoice_correction_has_settlements' : 'invalid_allocation_target')), $result['message']);
            }
            $this->assertSame($scenario === 'correction_first' ? 0 : 1, Settlement::query()->where('is_reversed', false)->count());
            $this->assertSame($scenario === 'correction_first' ? 3 : 2, JournalEntry::query()->count());
            $this->assertSame($scenario === 'correction_first' ? 0 : 1, Reconciliation::query()->count());
            $this->assertSame($scenario === 'correction_first', $invoice->fresh()->openItem->is_reversed);
            $this->assertTrue(app(AuditChainVerifier::class)->verify($entity->id)->isValid());
            app(JournalIntegrityVerifier::class)->assertValid($entity->id);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(1);
            }
            unlink($ipc);
        }
    }

    private function waitForLock(Process $process, string $ipc): void
    {
        $deadline = microtime(true) + 30;
        $state = null;
        while (microtime(true) < $deadline) {
            $state = json_decode(file_get_contents($ipc), true);
            if (isset($state['connection'])) {
                $query = $this->admin->prepare('SELECT 1 FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE
                    WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ?');
                $query->execute([$state['connection'], $this->database, 'accounting_legal_entities']);
                if ($query->fetchColumn() !== false) {
                    $this->assertTrue($process->isRunning());

                    return;
                }
            }
            if (! $process->isRunning()) {
                $this->fail('Worker exited before a lock wait: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(50000);
        }
        $this->fail('MySQL did not report the worker waiting on the held entity lock. IPC: '
            .json_encode($state).' Output: '.$process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function worker(): void
    {
        $this->actingAs(User::query()->findOrFail($this->job['user']));
        DB::statement('SET SESSION innodb_lock_wait_timeout = 45');
        file_put_contents($this->job['ipc'], json_encode(['connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR));
        try {
            if ($this->job['operation'] === 'issue') {
                $record = app(IssueSalesInvoice::class)->issue(Document::query()->findOrFail($this->job['document']));
            } else {
                $record = app(AssignStatementLine::class)->handle(BankStatementLine::query()->findOrFail($this->job['line']),
                    ['purpose' => 'settle_open_item', 'open_item_id' => $this->job['item']]);
            }
            $result = ['status' => 'ok', 'id' => $record->id];
        } catch (DocumentException|ReconciliationException $exception) {
            $result = ['status' => 'rejected', 'message' => $exception->getMessage()];
        }
        file_put_contents($this->job['ipc'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertTrue(true);
    }
}
