<?php

namespace FilamentAccounting\Tests\Ledger;

use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Exceptions\ClosedPeriodException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Exceptions\UnbalancedJournalException;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Ledger\ReverseJournalCommand;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LedgerEngineTest extends TestCase
{
    #[Test]
    public function it_posts_a_balanced_journal(): void
    {
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');

        $entry = app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'manual',
            sourceId: '1',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 10000, 'EUR'),
                JournalLineDraft::credit($revenue, 10000, 'EUR'),
            ],
            idempotencyKey: 'manual-1',
        ));

        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertCount(2, $entry->lines);
        $this->assertSame(10000, (int) $entry->lines->sum('base_debit_minor'));
        $this->assertSame(10000, (int) $entry->lines->sum('base_credit_minor'));
    }

    #[Test]
    public function it_rejects_one_line_unbalanced_and_zero_lines(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
        $engine = app(LedgerEngine::class);

        try {
            $engine->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: '2026-03-01',
                sourceType: 'manual',
                sourceId: 'x',
                currency: 'EUR',
                baseCurrency: 'EUR',
                lines: [JournalLineDraft::debit($bank, 100, 'EUR')],
            ));
            $this->fail('Expected one-line rejection');
        } catch (UnbalancedJournalException) {
            $this->assertTrue(true);
        }

        try {
            $engine->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: '2026-03-01',
                sourceType: 'manual',
                sourceId: 'y',
                currency: 'EUR',
                baseCurrency: 'EUR',
                lines: [
                    JournalLineDraft::debit($bank, 100, 'EUR'),
                    JournalLineDraft::credit($revenue, 90, 'EUR'),
                ],
            ));
            $this->fail('Expected unbalanced rejection');
        } catch (UnbalancedJournalException) {
            $this->assertTrue(true);
        }

        try {
            $engine->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: '2026-03-01',
                sourceType: 'manual',
                sourceId: 'z',
                currency: 'EUR',
                baseCurrency: 'EUR',
                lines: [
                    new JournalLineDraft($bank, 0, 0, 'EUR', 0, 0),
                    JournalLineDraft::credit($revenue, 0, 'EUR'),
                ],
            ));
            $this->fail('Expected zero-line rejection');
        } catch (UnbalancedJournalException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function posted_journals_are_immutable(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
        $entry = app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'manual',
            sourceId: '1',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 500, 'EUR'),
                JournalLineDraft::credit($revenue, 500, 'EUR'),
            ],
        ));

        $this->expectException(PostedRecordImmutableException::class);
        $entry->description = 'changed';
        $entry->save();
    }

    #[Test]
    public function reversal_creates_linked_entry_and_keeps_original_posted(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
        $engine = app(LedgerEngine::class);
        $original = $engine->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'manual',
            sourceId: '1',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 500, 'EUR'),
                JournalLineDraft::credit($revenue, 500, 'EUR'),
            ],
        ));

        $reversal = $engine->reverse(new ReverseJournalCommand(
            journalEntryId: (int) $original->getKey(),
            postedOn: '2026-03-02',
            reason: 'correction',
        ));

        $this->assertSame(JournalStatus::Posted, $original->fresh()->status);
        $this->assertSame($original->getKey(), $reversal->reverses_id);
        $this->assertSame((int) $original->lines->first()->debit_minor, (int) $reversal->lines->first()->credit_minor);
    }

    #[Test]
    public function it_rejects_hard_closed_periods(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
        app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'setup',
            sourceId: '0',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 1, 'EUR'),
                JournalLineDraft::credit($revenue, 1, 'EUR'),
            ],
        ));

        AccountingPeriod::query()
            ->where('legal_entity_id', $entity->getKey())
            ->whereDate('starts_on', '<=', '2026-03-01')
            ->update(['state' => PeriodState::HardClosed]);

        $this->expectException(ClosedPeriodException::class);
        app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-02',
            sourceType: 'manual',
            sourceId: '2',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 100, 'EUR'),
                JournalLineDraft::credit($revenue, 100, 'EUR'),
            ],
        ));
    }

    #[Test]
    public function posting_is_idempotent_on_entity_and_key(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
        $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
        $command = new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: '2026-03-01',
            sourceType: 'manual',
            sourceId: 'dup',
            currency: 'EUR',
            baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit($bank, 800, 'EUR'),
                JournalLineDraft::credit($revenue, 800, 'EUR'),
            ],
            idempotencyKey: 'same-key',
        );

        $first = app(LedgerEngine::class)->post($command);
        $second = app(LedgerEngine::class)->post($command);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, JournalEntry::query()->where('legal_entity_id', $entity->getKey())->where('idempotency_key', 'same-key')->count());
    }
}
