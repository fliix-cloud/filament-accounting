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
    public function bounded_range_within_limit_returns_the_whole_range_and_no_next(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-08-30');

        [$chunkFrom, $chunkTo, $next] = $svc->boundedRange($from, $to);

        $this->assertEquals('2026-06-01', $chunkFrom->toDateString());
        $this->assertEquals('2026-08-30', $chunkTo->toDateString());
        $this->assertNull($next);
    }

    #[Test]
    public function bounded_range_exceeds_limit_takes_the_oldest_window_and_returns_the_next_frontier(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-09-11');

        [$chunkFrom, $chunkTo, $next] = $svc->boundedRange($from, $to);

        // Oldest window of 90 days: 2026-01-01 .. 2026-04-01 (Jan 31 + Feb 28 + Mar 31).
        $this->assertEquals('2026-01-01', $chunkFrom->toDateString());
        $this->assertEquals('2026-04-01', $chunkTo->toDateString());
        $this->assertNotNull($next);
        $this->assertEquals('2026-04-02', $next->toDateString());
    }

    #[Test]
    public function bounded_range_exactly_at_limit_does_not_chunk(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 30);
        $from = Carbon::parse('2026-08-12');
        $to = Carbon::parse('2026-09-11');

        [$chunkFrom, $chunkTo, $next] = $svc->boundedRange($from, $to);

        $this->assertEquals('2026-08-12', $chunkFrom->toDateString());
        $this->assertEquals('2026-09-11', $chunkTo->toDateString());
        $this->assertNull($next);
    }

    #[Test]
    public function a_long_gap_drains_oldest_first_across_chunks_until_the_marker_clears(): void
    {
        $svc = app(TransactionSyncService::class);
        config()->set('filament-accounting.banking.fints.sync.max_range_days', 90);
        $gapStart = Carbon::today()->subDays(200);
        $to = Carbon::today();

        // Simulate repeated syncs: each call takes the next chunk from the frontier.
        $marker = $gapStart;
        $chunks = [];
        $steps = 0;
        while ($marker instanceof Carbon && $steps < 10) {
            [$f, $t, $marker] = $svc->boundedRange($marker, $to);
            $chunks[] = [$f->toDateString(), $t->toDateString()];
            $steps++;
        }

        // A 200-day gap at a 90-day window is three chunks, then the marker clears.
        $this->assertSame(3, $steps);
        $this->assertNull($marker);

        // The first chunk starts at the oldest uncovered date ...
        $this->assertEquals($gapStart->toDateString(), $chunks[0][0]);

        // ... chunks tile oldest-first (no destination: each starts where the last
        // ended), and the final chunk reaches today …
        for ($i = 1; $i < $steps; $i++) {
            $this->assertEquals(
                Carbon::parse($chunks[$i - 1][1])->addDay()->toDateString(),
                $chunks[$i][0],
                'Chunks must tile without holes.',
            );
        }
        $this->assertEquals($to->toDateString(), $chunks[$steps - 1][1]);
    }

    #[Test]
    public function mark_sync_completed_clears_the_marker_on_the_final_chunk(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(5);
        $account->save();

        // Final chunk: requested_from_date is null → the gap is drained.
        $run = $this->makeRun($connection, $account,
            Carbon::today()->subDays(5), Carbon::today(), null);
        $run->save();

        $svc = app(TransactionSyncService::class);
        $svc->markSyncCompleted($account, $run, ['imported' => 3, 'updated' => 2]);

        $account->refresh();
        $this->assertNull($account->catch_up_from);
        $this->assertNotNull($account->last_transaction_sync_at);
    }

    #[Test]
    public function mark_sync_completed_advances_the_marker_when_chunks_remain(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->makeBankConnection($entity);
        $account = $this->makeBankAccountWithConnection($entity, $connection);
        $account->catch_up_from = Carbon::today()->subDays(200);
        $account->save();

        // This chunk covered the oldest 90 days; the next uncovered frontier is
        // recorded as requested_from_date and must move the marker forward.
        $nextFrontier = Carbon::today()->subDays(109);
        $run = $this->makeRun($connection, $account,
            Carbon::today()->subDays(200), Carbon::today()->subDays(110), $nextFrontier);
        $run->save();

        $svc = app(TransactionSyncService::class);
        $svc->markSyncCompleted($account, $run, ['imported' => 3, 'updated' => 2]);

        $account->refresh();
        $this->assertNotNull($account->catch_up_from);
        $this->assertEquals($nextFrontier->toDateString(), $account->catch_up_from->toDateString());
        // The watermark advances to the chunk's end, not now(), while draining.
        $this->assertEquals(Carbon::today()->subDays(110)->toDateString(), $account->last_transaction_sync_at->toDateString());
    }

    private function makeRun(BankConnection $connection, AccountingBankAccount $account, Carbon $from, Carbon $to, ?Carbon $next): BankSyncRun
    {
        return new BankSyncRun([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'legal_entity_id' => $account->legal_entity_id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Completed,
            'from_date' => $from,
            'to_date' => $to,
            'requested_from_date' => $next,
            'item_count' => 5,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
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
