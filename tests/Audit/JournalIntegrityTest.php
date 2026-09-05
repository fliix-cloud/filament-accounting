<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Audit\JournalSnapshot;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Exceptions\AuditChainCompromisedException;
use FilamentAccounting\Exceptions\JournalIntegrityException;
use FilamentAccounting\Export\GenericJournalCsvExporter;
use FilamentAccounting\Filament\Resources\JournalEntryResource\Pages\ViewJournalEntry;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Ledger\ReverseJournalCommand;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class JournalIntegrityTest extends TestCase
{
    #[Test]
    public function posting_and_reversal_have_complete_verifiable_evidence_and_retry_does_not_duplicate_it(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $entry = $this->postJournal($entity);
        $retry = $this->postJournal($entity);
        $this->assertTrue($entry->is($retry));
        $event = AuditEvent::query()->where('operation', 'journal.posted')->sole();
        $snapshot = app(JournalSnapshot::class)->capture($entry->fresh('lines'));
        $this->assertEquals($snapshot, $event->payload['journal_snapshot']);
        $this->assertSame(app(JournalSnapshot::class)->hash($snapshot), $event->payload['snapshot_sha256']);
        $this->assertSame('1200', $snapshot['lines'][0]['account_snapshot']['code']);
        $this->assertSame(2026, $snapshot['entry']['period_snapshot']['fiscal_year']);

        $reversal = app(LedgerEngine::class)->reverse(new ReverseJournalCommand(
            journalEntryId: (int) $entry->getKey(), postedOn: '2026-03-11', reason: 'Correction',
        ));
        $this->assertSame($entry->getKey(), $reversal->reverses_id);
        $report = $this->report(0);
        $this->assertTrue($report['valid']);
        $this->assertSame(2, $report['legal_entities'][0]['ledger']['posted_entry_count']);
        $this->assertSame(2, AuditEvent::query()->where('operation', 'journal.posted')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function journalMutations(): array
    {
        return [
            'balanced amount rewrite' => ['amounts', 'journal_snapshot_mismatch'],
            'account substitution' => ['account', 'journal_snapshot_mismatch'],
            'source reassignment' => ['source', 'journal_snapshot_mismatch'],
            'exchange rate rewrite' => ['rate', 'journal_snapshot_mismatch'],
            'historical account rewrite' => ['account_snapshot', 'journal_snapshot_mismatch'],
            'historical period rewrite' => ['period_snapshot', 'journal_snapshot_mismatch'],
            'missing historical values' => ['missing_snapshot', 'journal_history_missing'],
            'tax reference rewrite' => ['tax', 'journal_snapshot_mismatch'],
            'line removal' => ['line_delete', 'journal_snapshot_mismatch'],
            'line insertion' => ['line_insert', 'journal_snapshot_mismatch'],
            'journal deletion' => ['journal_delete', 'journal_target_missing'],
            'status downgrade' => ['status', 'journal_status_changed'],
            'entity reassignment' => ['entity', 'journal_target_missing'],
        ];
    }

    #[Test]
    #[DataProvider('journalMutations')]
    public function sql_mutation_is_detected_even_when_the_audit_chain_itself_is_valid(string $mutation, string $code): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $entry = $this->postJournal($entity);
        $connection = $entry->getConnection();
        $entries = $connection->table('accounting_journal_entries')->where('id', $entry->getKey());
        $lines = $connection->table('accounting_journal_lines')->where('journal_entry_id', $entry->getKey());
        $first = $entry->lines->firstOrFail();

        switch ($mutation) {
            case 'amounts':
                $lines->update([
                    'debit_minor' => $connection->raw('debit_minor * 2'),
                    'credit_minor' => $connection->raw('credit_minor * 2'),
                    'base_debit_minor' => $connection->raw('base_debit_minor * 2'),
                    'base_credit_minor' => $connection->raw('base_credit_minor * 2'),
                ]);
                break;
            case 'account':
                $lines->update(['ledger_account_id' => $entity->ledgerAccounts()->where('code', '8400')->value('id')]);
                break;
            case 'source':
                $entries->update(['source_id' => 'another-source']);
                break;
            case 'rate':
                $entries->update(['exchange_rate' => '9.99']);
                break;
            case 'account_snapshot':
                $lines->update(['account_snapshot' => json_encode(array_merge($first->account_snapshot, ['name' => 'Rewritten']))]);
                break;
            case 'period_snapshot':
                $entries->update(['period_snapshot' => json_encode(array_merge($entry->period_snapshot, ['fiscal_year' => 2030]))]);
                break;
            case 'missing_snapshot':
                $lines->update(['account_snapshot' => null]);
                break;
            case 'tax':
                $lines->update(['tax_rule_version_id' => 999999]);
                break;
            case 'line_delete':
                $lines->where('id', $first->getKey())->delete();
                break;
            case 'line_insert':
                $attributes = $first->getAttributes();
                unset($attributes['id']);
                $attributes['position'] = 3;
                $connection->table('accounting_journal_lines')->insert($attributes);
                break;
            case 'journal_delete':
                $lines->delete();
                $entries->delete();
                break;
            case 'status':
                $entries->update(['status' => 'draft']);
                break;
            case 'entity':
                $other = $this->makeEntity(['legal_name' => 'Other GmbH']);
                $entries->update(['legal_entity_id' => $other->getKey()]);
                break;
        }

        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $entity->getKey())->isValid());
        $report = $this->report(1);
        $this->assertFalse($report['valid']);
        $this->assertContains($code, array_column($report['legal_entities'][0]['ledger']['issues'], 'code'));

        $this->expectException(JournalIntegrityException::class);
        app(GenericJournalCsvExporter::class)->export($entity, '2026-03-01', '2026-03-31');
    }

    #[Test]
    public function missing_and_duplicate_posting_events_are_not_grandfathered_in(): void
    {
        $entity = $this->makeEntity();
        $entry = $this->postJournal($entity);
        $event = AuditEvent::query()->where('operation', 'journal.posted')->sole();
        app(AuditLogger::class)->log($entity, 'journal.posted', $entry, $event->payload);
        $result = app(JournalIntegrityVerifier::class)->verify((int) $entity->getKey());
        $this->assertContains('journal_evidence_duplicate', array_column($result['issues'], 'code'));
        $entry->getConnection()->table('accounting_audit_events')->delete();
        $result = app(JournalIntegrityVerifier::class)->verify((int) $entity->getKey());
        $this->assertContains('journal_evidence_missing', array_column($result['issues'], 'code'));
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidEvidence(): array
    {
        return [
            'absent snapshot' => [null, 'journal_snapshot_missing'],
            'unsupported schema' => [['schema_version' => 99], 'journal_snapshot_version_unsupported'],
            'invalid canonical value' => [['schema_version' => 1, 'amount' => 1.5], 'journal_snapshot_invalid'],
            'changed snapshot digest' => [['schema_version' => 1], 'journal_snapshot_hash_mismatch'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEvidence')]
    public function malformed_evidence_is_reported_without_crashing_the_command(mixed $snapshot, string $code): void
    {
        $entity = $this->makeEntity();
        $entry = $this->postJournal($entity);
        $event = AuditEvent::query()->where('operation', 'journal.posted')->sole();
        $payload = $event->payload;
        $payload['journal_snapshot'] = $snapshot;
        $entry->getConnection()->table('accounting_audit_events')->where('id', $event->getKey())->update(['payload' => json_encode($payload)]);
        $report = $this->report(1);
        $this->assertContains($code, array_column($report['legal_entities'][0]['ledger']['issues'], 'code'));
    }

    #[Test]
    public function historical_csv_is_stable_after_master_data_changes_and_json_key_reordering(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entry = $this->postJournal($entity);
        $exporter = app(GenericJournalCsvExporter::class);
        $before = $exporter->export($entity, '2026-03-01', '2026-03-31');
        $entity->ledgerAccounts()->where('code', '1200')->update(['code' => '1200-NEW', 'name' => 'Renamed bank']);
        $entry->period->update(['fiscal_year' => 2030, 'period_number' => 12]);
        foreach ($entry->lines as $line) {
            $entry->getConnection()->table('accounting_journal_lines')->where('id', $line->getKey())
                ->update(['account_snapshot' => json_encode(array_reverse($line->account_snapshot, true))]);
        }
        $this->report(0);
        $this->assertSame($before, $exporter->export($entity, '2026-03-01', '2026-03-31'));
        $this->assertStringNotContainsString('1200-NEW', $before);
        $this->assertStringContainsString('2026-3', $before);
        Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertOk()->assertSee('1200')->assertDontSee('1200-NEW');
        $this->assertCount(1, explode("\n", trim($exporter->export($entity, '2026-04-01', '2026-04-30'))));
    }

    #[Test]
    public function posting_rolls_back_if_the_audit_append_fails(): void
    {
        $entity = $this->makeEntity();
        $entity->getConnection()->table('accounting_audit_chain_heads')->insert([
            'legal_entity_id' => $entity->getKey(), 'last_sequence' => 1,
            'last_event_hash' => str_repeat('0', 64), 'event_count' => 1, 'updated_at' => now(),
        ]);
        try {
            $this->postJournal($entity);
            $this->fail('Unsealed postings must not survive a failed audit append.');
        } catch (AuditChainCompromisedException) {
            $this->assertDatabaseCount('accounting_journal_entries', 0);
            $this->assertDatabaseCount('accounting_journal_lines', 0);
            $this->assertDatabaseCount('accounting_audit_events', 0);
        }
    }

    #[Test]
    public function an_independent_anchor_detects_a_coordinated_rewrite_of_journal_and_local_hashes(): void
    {
        Storage::fake('audit-anchors');
        config()->set('filament-accounting.audit.anchor.disk', 'audit-anchors');
        config()->set('filament-accounting.audit.anchor.required', true);
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
        $entity = $this->makeEntity();
        $entry = $this->postJournal($entity);
        $this->assertSame(0, Artisan::call('filament-accounting:audit-anchor', ['--legal-entity' => $entity->uuid, '--json' => true]));

        $connection = $entry->getConnection();
        $connection->table('accounting_journal_entries')->where('id', $entry->getKey())->update(['description' => 'Coordinated rewrite']);
        $event = AuditEvent::query()->where('operation', 'journal.posted')->sole();
        $snapshot = app(JournalSnapshot::class)->capture($entry->fresh('lines'));
        $payload = $event->payload;
        $payload['journal_snapshot'] = $snapshot;
        $payload['snapshot_sha256'] = app(JournalSnapshot::class)->hash($snapshot);
        $hasher = app(AuditEventHasher::class);
        $canonical = $hasher->canonicalPayload($payload);
        $hash = $hasher->hash(array_merge($event->getAttributes(), ['canonical_payload' => $canonical]));
        $connection->table('accounting_audit_events')->where('id', $event->getKey())->update([
            'payload' => json_encode($payload), 'canonical_payload' => $canonical, 'event_hash' => $hash,
        ]);
        $connection->table('accounting_audit_chain_heads')->where('legal_entity_id', $entity->getKey())->update(['last_event_hash' => $hash]);

        $this->assertSame([], app(JournalIntegrityVerifier::class)->verify((int) $entity->getKey())['issues']);
        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $entity->getKey())->isValid());
        $report = $this->report(1);
        $this->assertNotEmpty($report['legal_entities'][0]['external_anchors']['issues']);
        $this->expectException(JournalIntegrityException::class);
        app(GenericJournalCsvExporter::class)->export($entity, '2026-03-01', '2026-03-31');
    }

    private function postJournal(LegalEntity $entity): JournalEntry
    {
        return app(LedgerEngine::class)->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(), postedOn: '2026-03-10',
            sourceType: 'manual', sourceId: 'integrity-test', currency: 'EUR', baseCurrency: 'EUR',
            lines: [
                JournalLineDraft::debit((int) $entity->ledgerAccounts()->where('code', '1200')->value('id'), 100, 'EUR', 'Bank'),
                JournalLineDraft::credit((int) $entity->ledgerAccounts()->where('code', '8400')->value('id'), 100, 'EUR', 'Revenue'),
            ],
            idempotencyKey: 'integrity-test',
        ));
    }

    /** @return array<string, mixed> */
    private function report(int $exitCode): array
    {
        $this->assertSame($exitCode, Artisan::call('filament-accounting:verify', ['--json' => true]));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
