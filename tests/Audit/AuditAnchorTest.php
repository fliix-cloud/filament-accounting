<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use PHPUnit\Framework\Attributes\Test;

class AuditAnchorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('audit-anchors');
        Storage::fake('audit-evidence');
        config()->set('filament-accounting.audit.anchor.disk', 'audit-anchors');
        config()->set('filament-accounting.audit.anchor.prefix', 'accounting/audit-anchors');
        config()->set('filament-accounting.audit.anchor.required', true);
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
    }

    #[Test]
    public function it_writes_idempotent_chained_external_anchors_and_reports_them_as_json(): void
    {
        $entity = $this->makeEntity();
        $logger = app(AuditLogger::class);
        $firstEvent = $logger->log($entity, 'test.first');

        $this->assertSame(0, Artisan::call('filament-accounting:audit-anchor', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ]));

        $firstReport = $this->jsonOutput();
        $this->assertTrue($firstReport['valid']);
        $this->assertSame($firstEvent->event_hash, $firstReport['results'][0]['anchor']['last_event_hash']);

        $directory = "accounting/audit-anchors/{$entity->uuid}";
        $this->assertCount(1, Storage::disk('audit-anchors')->files($directory));

        $this->assertSame(0, Artisan::call('filament-accounting:audit-anchor', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ]));
        $this->assertCount(1, Storage::disk('audit-anchors')->files($directory));

        $secondEvent = $logger->log($entity, 'test.second');
        $this->assertSame(0, Artisan::call('filament-accounting:audit-anchor', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ]));

        $files = Storage::disk('audit-anchors')->files($directory);
        sort($files);
        $this->assertCount(2, $files);

        $firstAnchor = json_decode(Storage::disk('audit-anchors')->get($files[0]), true, 512, JSON_THROW_ON_ERROR);
        $secondAnchor = json_decode(Storage::disk('audit-anchors')->get($files[1]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($firstAnchor['anchor_hash'], $secondAnchor['previous_anchor_hash']);
        $this->assertSame($secondEvent->event_hash, $secondAnchor['last_event_hash']);

        $this->assertSame(0, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $verifyReport = $this->jsonOutput();

        $this->assertTrue($verifyReport['valid']);
        $this->assertSame(2, $verifyReport['legal_entities'][0]['external_anchors']['anchor_count']);
        $this->assertSame(2, $verifyReport['legal_entities'][0]['external_anchors']['last_anchored_sequence']);
        $this->assertSame([], $verifyReport['legal_entities'][0]['external_anchors']['issues']);
    }

    #[Test]
    public function it_detects_a_database_rewrite_even_when_the_internal_chain_head_was_recomputed(): void
    {
        $entity = $this->makeEntity();
        $event = app(AuditLogger::class)->log($entity, 'test.original');

        $this->assertSame(0, Artisan::call('filament-accounting:audit-anchor', [
            '--legal-entity' => $entity->uuid,
        ]));

        $attributes = $event->getAttributes();
        $attributes['operation'] = 'test.forged';
        $forgedHash = app(AuditEventHasher::class)->hash($attributes);

        DB::table('accounting_audit_events')
            ->where('id', $event->getKey())
            ->update([
                'operation' => 'test.forged',
                'event_hash' => $forgedHash,
            ]);
        DB::table('accounting_audit_chain_heads')
            ->where('legal_entity_id', $entity->getKey())
            ->update(['last_event_hash' => $forgedHash]);

        $this->assertSame(1, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = $this->jsonOutput();
        $anchorCodes = array_column($report['legal_entities'][0]['external_anchors']['issues'], 'code');

        $this->assertSame([], $report['legal_entities'][0]['audit_chain']['issues']);
        $this->assertContains('external_anchor_event_mismatch', $anchorCodes);
    }

    #[Test]
    public function required_external_anchors_cannot_be_silently_missing(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.not-anchored');

        $this->assertSame(1, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = $this->jsonOutput();

        $this->assertSame(
            ['external_anchor_missing'],
            array_column($report['legal_entities'][0]['external_anchors']['issues'], 'code'),
        );
    }

    #[Test]
    public function it_refuses_to_write_without_an_explicit_immutable_storage_attestation(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.unattested');
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', false);

        $this->assertSame(1, Artisan::call('filament-accounting:audit-anchor', [
            '--legal-entity' => $entity->uuid,
            '--json' => true,
        ]));
        $report = $this->jsonOutput();

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('Refusing to write', $report['results'][0]['error']);
        $this->assertSame([], Storage::disk('audit-anchors')->allFiles());
    }

    #[Test]
    public function it_rejects_unexpected_fields_added_to_an_external_anchor_object(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.anchor-object-tamper');
        Artisan::call('filament-accounting:audit-anchor', ['--legal-entity' => $entity->uuid]);

        $directory = "accounting/audit-anchors/{$entity->uuid}";
        $path = Storage::disk('audit-anchors')->files($directory)[0];
        $anchor = json_decode(Storage::disk('audit-anchors')->get($path), true, 512, JSON_THROW_ON_ERROR);
        $anchor['unexpected_field'] = 'tampered';
        Storage::disk('audit-anchors')->put($path, json_encode($anchor, JSON_THROW_ON_ERROR));

        $this->assertSame(1, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = $this->jsonOutput();

        $this->assertSame(
            ['external_anchor_unreadable'],
            array_column($report['legal_entities'][0]['external_anchors']['issues'], 'code'),
        );
    }

    #[Test]
    public function exported_evidence_can_be_verified_after_the_source_audit_records_are_unavailable(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.offline', payload: ['amount_minor' => 1250]);
        Artisan::call('filament-accounting:audit-anchor', ['--legal-entity' => $entity->uuid]);

        $this->assertSame(0, Artisan::call('filament-accounting:audit-export', [
            'legal-entity' => $entity->uuid,
            'path' => 'exports/audit-evidence.json',
            '--disk' => 'audit-evidence',
            '--json' => true,
        ]));
        Storage::disk('audit-evidence')->assertExists('exports/audit-evidence.json');

        DB::table('accounting_audit_events')->delete();
        DB::table('accounting_audit_chain_heads')->delete();

        $this->assertSame(0, Artisan::call('filament-accounting:audit-verify-file', [
            'path' => 'exports/audit-evidence.json',
            '--disk' => 'audit-evidence',
            '--json' => true,
        ]));
        $report = $this->jsonOutput();

        $this->assertTrue($report['valid']);
        $this->assertSame(1, $report['audit_chain']['event_count']);
        $this->assertSame(1, $report['external_anchors']['anchor_count']);
    }

    #[Test]
    public function offline_verification_detects_a_fully_rehashed_evidence_history_against_its_anchor(): void
    {
        $entity = $this->makeEntity();
        app(AuditLogger::class)->log($entity, 'test.original');
        Artisan::call('filament-accounting:audit-anchor', ['--legal-entity' => $entity->uuid]);
        Artisan::call('filament-accounting:audit-export', [
            'legal-entity' => $entity->uuid,
            'path' => 'exports/forged.json',
            '--disk' => 'audit-evidence',
        ]);

        $evidence = json_decode(
            Storage::disk('audit-evidence')->get('exports/forged.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $evidence['audit_chain']['events'][0]['operation'] = 'test.forged';
        $forgedEventHash = app(AuditEventHasher::class)->hash($evidence['audit_chain']['events'][0]);
        $evidence['audit_chain']['events'][0]['event_hash'] = $forgedEventHash;
        $evidence['audit_chain']['head']['last_event_hash'] = $forgedEventHash;
        unset($evidence['evidence_hash']);
        $evidence['evidence_hash'] = hash('sha256', app(CanonicalJson::class)->encode($evidence));
        Storage::disk('audit-evidence')->put(
            'exports/forged.json',
            app(CanonicalJson::class)->encode($evidence),
        );

        $this->assertSame(1, Artisan::call('filament-accounting:audit-verify-file', [
            'path' => 'exports/forged.json',
            '--disk' => 'audit-evidence',
            '--json' => true,
        ]));
        $report = $this->jsonOutput();

        $this->assertSame([], $report['evidence']['issues']);
        $this->assertSame([], $report['audit_chain']['issues']);
        $this->assertContains(
            'external_anchor_event_mismatch',
            array_column($report['external_anchors']['issues'], 'code'),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function jsonOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
