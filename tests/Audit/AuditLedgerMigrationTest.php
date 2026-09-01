<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class AuditLedgerMigrationTest extends TestCase
{
    #[Test]
    public function it_backfills_a_legacy_audit_log_into_a_verifiable_chain(): void
    {
        $entity = $this->makeEntity();
        $migration = require __DIR__.'/../../database/migrations/2026_09_01_000003_harden_accounting_audit_events.php';

        $this->assertInstanceOf(Migration::class, $migration);
        $migration->down();

        DB::table('accounting_audit_events')->insert([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'legal_entity_id' => $entity->getKey(),
            'actor_type' => null,
            'actor_id' => null,
            'operation' => 'legacy.event',
            'target_type' => null,
            'target_id' => null,
            'reason' => null,
            'payload' => json_encode(['z' => 2, 'a' => 1], JSON_THROW_ON_ERROR),
            'correlation_id' => null,
            'request_id' => 'legacy-request',
            'occurred_at' => '2026-08-31 12:00:00',
            'created_at' => '2026-08-31 12:00:01',
            'updated_at' => '2026-08-31 12:00:01',
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('accounting_audit_events', 'event_hash'));
        $this->assertDatabaseHas('accounting_audit_events', [
            'legal_entity_id' => $entity->getKey(),
            'sequence' => 1,
            'canonical_payload' => '{"a":1,"z":2}',
        ]);
        $this->assertDatabaseHas('accounting_audit_chain_heads', [
            'legal_entity_id' => $entity->getKey(),
            'last_sequence' => 1,
            'event_count' => 1,
        ]);
        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $entity->getKey())->isValid());
    }
}
