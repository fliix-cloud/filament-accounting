<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Exceptions\AuditChainCompromisedException;
use FilamentAccounting\Exceptions\AuditEventImmutableException;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class AuditLedgerTest extends TestCase
{
    #[Test]
    public function it_builds_an_independent_canonical_hash_chain_for_each_legal_entity(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        config()->set('filament-accounting.audit.application_version', '1.2.3');
        config()->set('filament-accounting.audit.application_commit', 'abcdef123456');
        $logger = app(AuditLogger::class);

        $first = $logger->log($entity, 'test.first', payload: [
            'z' => 'last',
            'nested' => ['token' => 'secret-value', 'a' => 'first'],
        ]);
        $second = $logger->log($entity, 'test.second', payload: ['amount_minor' => 100]);

        $otherEntity = $this->makeEntity(['legal_name' => 'Other GmbH']);
        $other = $logger->log($otherEntity, 'test.other');

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame($first->event_hash, $second->previous_hash);
        $this->assertSame(1, $other->sequence);
        $this->assertNull($other->previous_hash);
        $this->assertSame(
            '{"nested":{"a":"first","token":"[redacted]"},"z":"last"}',
            $first->canonical_payload,
        );
        $this->assertSame('1.2.3', $first->application_version);
        $this->assertSame('abcdef123456', $first->application_commit);
        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $entity->getKey())->isValid());
        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $otherEntity->getKey())->isValid());

        $this->assertDatabaseHas('accounting_audit_chain_heads', [
            'legal_entity_id' => $entity->getKey(),
            'last_sequence' => 2,
            'last_event_hash' => $second->event_hash,
            'event_count' => 2,
        ]);
    }

    #[Test]
    public function audit_events_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $event = app(AuditLogger::class)->log($this->makeEntity(), 'test.immutable');

        try {
            $event->operation = 'test.changed';
            $event->save();
            $this->fail('Expected an audit event update to be rejected.');
        } catch (AuditEventImmutableException) {
            $this->assertTrue(true);
        }

        $event->refresh();

        $this->expectException(AuditEventImmutableException::class);
        $event->delete();
    }

    #[Test]
    public function verification_detects_direct_payload_tampering(): void
    {
        $entity = $this->makeEntity();
        $event = app(AuditLogger::class)->log($entity, 'test.original', payload: ['amount_minor' => 100]);

        DB::table('accounting_audit_events')
            ->where('id', $event->getKey())
            ->update(['payload' => json_encode(['amount_minor' => 999], JSON_THROW_ON_ERROR)]);

        $result = app(AuditChainVerifier::class)->verify((int) $entity->getKey());
        $codes = array_map(fn ($issue): string => $issue->code, $result->issues);

        $this->assertFalse($result->isValid());
        $this->assertContains('canonical_payload_mismatch', $codes);
        $this->artisan('filament-accounting:verify')
            ->expectsOutputToContain('canonical_payload_mismatch')
            ->assertFailed();
    }

    #[Test]
    public function verification_detects_a_directly_deleted_chain_tail(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.first');
        $tail = app(AuditLogger::class)->log($entity, 'test.tail');

        DB::table('accounting_audit_events')->where('id', $tail->getKey())->delete();

        $result = app(AuditChainVerifier::class)->verify((int) $entity->getKey());
        $codes = array_map(fn ($issue): string => $issue->code, $result->issues);

        $this->assertContains('chain_head_mismatch', $codes);

        $this->expectException(AuditChainCompromisedException::class);
        app(AuditLogger::class)->log($entity, 'test.must-not-append');
    }

    #[Test]
    public function canonical_payloads_reject_float_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CanonicalJson::class)->encode(['amount' => 1.1]);
    }
}
