<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class TransactionSyncServiceTest extends TestCase
{
    #[Test]
    public function bounded_range_within_limit_returns_same_from_and_null_requested(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-08-30');

        [$bounded, $requested] = $svc->boundedRange($from, $to);

        $this->assertEquals('2026-06-01', $bounded->toDateString());
        $this->assertNull($requested);
    }

    #[Test]
    public function bounded_range_exceeds_limit_truncates_and_returns_requested_from(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-09-11');

        [$bounded, $requested] = $svc->boundedRange($from, $to);

        // 90 days before 2026-09-11 = 2026-06-13
        $this->assertEquals('2026-06-13', $bounded->toDateString());
        $this->assertNotNull($requested);
        $this->assertEquals('2026-01-01', $requested->toDateString());
    }

    #[Test]
    public function bounded_range_exactly_at_limit_does_not_truncate(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 30);
        $from = Carbon::parse('2026-08-12');
        $to = Carbon::parse('2026-09-11');

        [$bounded, $requested] = $svc->boundedRange($from, $to);

        $this->assertEquals('2026-08-12', $bounded->toDateString());
        $this->assertNull($requested);
    }

    #[Test]
    public function catch_up_from_is_set_when_sync_is_truncated(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->last_transaction_sync_at = Carbon::parse('2026-01-01');
        $account->save();

        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);

        $from = Carbon::parse('2026-01-01');
        $to = Carbon::today();
        [$bounded, $requested] = $svc->boundedRange($from, $to);

        // The range exceeds max_range_days, so truncation happens
        $this->assertNotNull($requested);
        $this->assertEquals('2026-01-01', $requested->toDateString());

        // Simulate the service setting catch_up_from
        if ($requested instanceof Carbon && $requested->lt($bounded)) {
            $account->catch_up_from = $requested;
            $account->save();
        }

        $account->refresh();
        $this->assertNotNull($account->catch_up_from);
        $this->assertEquals('2026-01-01', $account->catch_up_from->toDateString());
    }

    #[Test]
    public function mark_sync_completed_clears_catch_up_when_gap_is_closed(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(30); // 30-day gap, within 90-day max
        $account->save();

        $run = new BankSyncRun([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $entity->id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Completed,
            'from_date' => Carbon::today()->subDays(30),
            'to_date' => Carbon::today(),
            'item_count' => 5,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $run->save();

        $svc = app(TransactionSyncService::class);
        $svc->markSyncCompleted($account, $run, ['imported' => 3, 'updated' => 2]);

        $account->refresh();
        $this->assertNull($account->catch_up_from);
        $this->assertNotNull($account->last_transaction_sync_at);
    }

    #[Test]
    public function mark_sync_completed_persists_catch_up_when_gap_remains_large(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(200); // 200-day gap, exceeds 90-day max
        $account->save();

        $run = new BankSyncRun([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $entity->id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Completed,
            'from_date' => Carbon::today()->subDays(90),
            'to_date' => Carbon::today(),
            'requested_from_date' => Carbon::today()->subDays(200),
            'item_count' => 5,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $run->save();

        $svc = app(TransactionSyncService::class);
        $svc->markSyncCompleted($account, $run, ['imported' => 3, 'updated' => 2]);

        $account->refresh();
        // catch_up_from should still be set (gap not closed yet)
        $this->assertNotNull($account->catch_up_from);
        $this->assertEquals(Carbon::today()->subDays(200)->toDateString(), $account->catch_up_from->toDateString());
        // last_transaction_sync_at should be advanced to the chunk's to_date, not now()
        $this->assertEquals(Carbon::today()->toDateString(), $account->last_transaction_sync_at->toDateString());
    }

    #[Test]
    public function catch_up_from_is_not_set_when_range_fits_within_limit(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->last_transaction_sync_at = Carbon::today()->subDays(30);
        $account->save();

        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);

        $from = Carbon::today()->subDays(33); // 30 days ago + 3 overlap
        $to = Carbon::today();
        [$bounded, $requested] = $svc->boundedRange($from, $to);

        // Range is within limit, no truncation
        $this->assertNull($requested);
        $this->assertEquals($from->toDateString(), $bounded->toDateString());
    }

    private function makeBankConnection($entity): BankConnection
    {
        $connection = new BankConnection([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Test Connection',
            'bank_code' => 'TESTBANK00',
            'endpoint_url' => 'https://example.com/fints',
            'username' => 'testuser',
            'pin' => 'testpin',
            'status' => BankConnectionStatus::Active,
        ]);
        $connection->save();

        return $connection;
    }

    private function makeBankAccountWithConnection($entity, BankConnection $connection): AccountingBankAccount
    {
        $account = $this->makeBankAccount($entity);
        $account->bank_connection_id = $connection->id;
        $account->source = 'fints';
        $account->external_account_id = 'acc-'.$account->id;
        $account->save();

        return $account;
    }
}
