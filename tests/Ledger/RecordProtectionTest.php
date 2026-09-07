<?php

namespace FilamentAccounting\Tests\Ledger;

use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Exceptions\ClosedPeriodException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\JournalLine;
use FilamentAccounting\Services\CloseAccountingPeriod;
use FilamentAccounting\Services\ReopenAccountingPeriod;
use FilamentAccounting\Services\ResolveAccountingPeriod;
use FilamentAccounting\Tests\Fixtures\User;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class RecordProtectionTest extends TestCase
{
    #[Test]
    public function closing_cannot_weaken_a_hard_lock_and_reopening_requires_its_own_permission_and_reason(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $period = app(ResolveAccountingPeriod::class)->covering($entity, '2026-03-01');
        $stale = $period->fresh();
        app(CloseAccountingPeriod::class)->handle($period);

        try {
            app(CloseAccountingPeriod::class)->handle($stale, hard: false);
            $this->fail('Close must not reopen posting through a soft lock.');
        } catch (ClosedPeriodException) {
            $this->assertSame(PeriodState::HardClosed, $period->fresh()->state);
        }
        try {
            app(ReopenAccountingPeriod::class)->handle($stale, " \t ");
            $this->fail('Reopen must require a meaningful reason.');
        } catch (ClosedPeriodException) {
            $this->assertSame(PeriodState::HardClosed, $period->fresh()->state);
        }
        Gate::define('accounting.periods.reopen', fn (User $user): bool => false);
        try {
            app(ReopenAccountingPeriod::class)->handle($stale, 'Correction');
            $this->fail('Close permission must not imply reopen permission.');
        } catch (AuthorizationException) {
            $this->assertSame(PeriodState::HardClosed, $period->fresh()->state);
        }
        Gate::define('accounting.periods.reopen', fn (User $user): bool => true);
        app(ReopenAccountingPeriod::class)->handle($stale, '  Correction  ');
        $event = AuditEvent::query()->where('operation', 'period.reopened')->sole();
        $this->assertSame(PeriodState::Open, $period->fresh()->state);
        $this->assertSame('hard_closed', $event->payload['before']);
        $this->assertSame('open', $event->payload['after']);
        $this->assertSame('Correction', $event->reason);
    }

    #[Test]
    public function closing_is_idempotent_and_blocks_backdated_posting(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $period = app(ResolveAccountingPeriod::class)->covering($entity, '2026-03-01');
        app(CloseAccountingPeriod::class)->handle($period);
        $closedAt = $period->fresh()->closed_at;
        $this->travel(1)->hours();
        app(CloseAccountingPeriod::class)->handle($period);
        $this->assertTrue($closedAt->equalTo($period->fresh()->closed_at));
        $this->assertSame(1, AuditEvent::query()->where('operation', 'period.closed')->count());

        $this->expectException(ClosedPeriodException::class);
        app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'test',
            sourceId: 'closed-period',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit((int) $entity->ledgerAccounts()->where('code', '1200')->value('id'), 100, 'EUR'),
                JournalLineDraft::credit((int) $entity->ledgerAccounts()->where('code', '8400')->value('id'), 100, 'EUR'),
            ],
        ));
    }

    #[Test]
    public function posted_lines_reject_reparenting_and_cached_draft_relations(): void
    {
        $entity = $this->makeEntity();
        $period = app(ResolveAccountingPeriod::class)->covering($entity, '2026-03-01');
        $attributes = [
            'legal_entity_id' => $entity->getKey(),
            'period_id' => $period->getKey(),
            'posted_on' => '2026-03-01',
            'status' => JournalStatus::Draft,
            'source_type' => 'test',
            'currency' => 'EUR',
            'base_currency' => 'EUR',
        ];
        $entry = JournalEntry::query()->create($attributes);
        $other = JournalEntry::query()->create($attributes);
        $stale = $entry->fresh();
        $line = JournalLine::query()->create([
            'journal_entry_id' => $entry->getKey(),
            'ledger_account_id' => $entity->ledgerAccounts()->where('code', '1200')->value('id'),
            'position' => 1,
            'debit_minor' => 100,
            'credit_minor' => 0,
            'currency' => 'EUR',
            'base_debit_minor' => 100,
            'base_credit_minor' => 0,
        ]);
        $line->load('journalEntry');
        $entry->update(['status' => JournalStatus::Posted]);

        foreach ([
            fn () => $line->update(['debit_minor' => 200]),
            fn () => $line->fresh()->update(['journal_entry_id' => $other->getKey()]),
            fn () => $line->delete(),
            fn () => $stale->update(['description' => 'Changed after posting']),
            fn () => $stale->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Posted data must remain immutable even through a stale model.');
            } catch (PostedRecordImmutableException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(100, $line->fresh()->debit_minor);
        $this->assertSame(JournalStatus::Posted, $entry->fresh()->status);
    }
}
