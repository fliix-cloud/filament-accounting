<?php

namespace FilamentAccounting\Tests\Reconciliation;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Events\ReconciliationFinalized;
use FilamentAccounting\Events\ReconciliationReversed;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ReverseReconciliation;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class ReconciliationConnectionTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function finalization_failure_rolls_back_accounting_writes_and_dispatches_only_after_commit(): void
    {
        [$line, $itemId] = $this->fixture();
        $before = AuditEvent::query()->count();
        $events = 0;
        Event::listen(ReconciliationFinalized::class, function () use (&$events): void {
            $events++;
        });
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->operation === 'reconciliation.finalized') {
                throw new \RuntimeException('Injected finalization failure');
            }
        });
        try {
            app(AssignStatementLine::class)->handle($line, ['purpose' => 'settle_open_item', 'open_item_id' => $itemId]);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected finalization failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertSame(0, Settlement::query()->count());
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame($before, AuditEvent::query()->count());
        $this->assertSame(0, $events);
        $connection = $line->getConnection();
        $connection->beginTransaction();
        try {
            app(AssignStatementLine::class)->handle($line, ['purpose' => 'settle_open_item', 'open_item_id' => $itemId]);
            $this->assertSame(0, $events);
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->assertSame(1, $events);
        $this->assertSame(1, Settlement::query()->count());
        $this->assertSame(0, DB::connection('sqlite')->table('accounting_reconciliations')->count());
    }

    #[Test]
    public function reversal_failure_restores_settlements_journal_and_audit_and_retry_succeeds(): void
    {
        [$line, $itemId] = $this->fixture();
        $payment = app(AssignStatementLine::class)->handle($line, ['purpose' => 'settle_open_item', 'open_item_id' => $itemId]);
        $before = AuditEvent::query()->count();
        $events = 0;
        Event::listen(ReconciliationReversed::class, function () use (&$events): void {
            $events++;
        });
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->operation === 'reconciliation.reversed') {
                throw new \RuntimeException('Injected reversal failure');
            }
        });
        try {
            app(ReverseReconciliation::class)->handle($payment, '2026-09-11', 'Correct allocation');
            $this->fail('Expected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected reversal failure', $exception->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertFalse(Settlement::query()->sole()->is_reversed);
        $this->assertSame(1, Reconciliation::query()->count());
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame($before, AuditEvent::query()->count());
        $this->assertSame(0, $events);
        $payment->getConnection()->transaction(function () use ($payment, &$events): void {
            app(ReverseReconciliation::class)->handle($payment, '2026-09-11', 'Correct allocation');
            $this->assertSame(0, $events);
        });
        $this->assertSame(1, $events);
        $this->assertSame(2, Settlement::query()->where('is_reversed', true)->count());
        $this->assertSame(3, JournalEntry::query()->count());
    }

    /** @return array{BankStatementLine, int} */
    private function fixture(): array
    {
        config()->set('database.connections.reconciliation_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('reconciliation_accounting')->getSchemaBuilder());
        try {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
                (require $path)->up();
            }
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'reconciliation_accounting');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-11', 'currency' => 'EUR',
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($this->makeBankAccount($entity), [
            new BankStatementLineData('connection-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-09-11', null, 'booked'),
        ]);

        return [BankStatementLine::query()->sole(), (int) $invoice->openItem->id];
    }
}
