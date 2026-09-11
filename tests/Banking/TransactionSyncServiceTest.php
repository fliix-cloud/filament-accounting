<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
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
}