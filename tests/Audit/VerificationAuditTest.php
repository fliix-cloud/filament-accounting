<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Events\VerificationCompleted;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class VerificationAuditTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function verify_record_writes_an_audit_event_and_dispatches_verification_completed(): void
    {
        $entity = $this->makeEntity();
        $before = AuditEvent::query()->count();
        Event::fake([VerificationCompleted::class]);

        $exitCode = Artisan::call('filament-accounting:verify', ['--json' => true, '--record' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before + 1, AuditEvent::query()->count());
        $event = AuditEvent::query()->latest('id')->first();
        $this->assertSame('audit.verification.completed', $event->operation);
        $this->assertSame((int) $entity->getKey(), (int) $event->legal_entity_id);
        $this->assertTrue($event->payload['valid']);
        $this->assertSame(0, $event->payload['issue_count']);
        $this->assertSame(0, $event->payload['pending_count']);

        Event::assertDispatched(VerificationCompleted::class, function (VerificationCompleted $e) use ($entity): bool {
            return (int) $e->entity->getKey() === (int) $entity->getKey()
                && $e->valid
                && $e->issueCount === 0;
        });
    }

    #[Test]
    public function verify_without_record_does_not_write_an_audit_event(): void
    {
        $this->makeEntity();
        $before = AuditEvent::query()->count();

        $exitCode = Artisan::call('filament-accounting:verify', ['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, AuditEvent::query()->count());
    }

    #[Test]
    public function verify_record_report_includes_pending_count(): void
    {
        $entity = $this->makeEntity();
        $exitCode = Artisan::call('filament-accounting:verify', ['--json' => true, '--record' => true]);
        $this->assertSame(0, $exitCode);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $entityReport = collect($report['legal_entities'])->firstWhere('legal_entity_id', $entity->getKey());
        $this->assertArrayHasKey('pending_count', $entityReport);
        $this->assertSame(0, $entityReport['pending_count']);

        $event = AuditEvent::query()->latest('id')->first();
        $this->assertSame(0, $event->payload['pending_count']);
    }

    #[Test]
    public function dataset_export_without_anchor_produces_a_valid_package(): void
    {
        Storage::fake('audit-evidence');
        config()->set('filament-accounting.storage.disk', 'audit-evidence');

        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $exitCode = Artisan::call('filament-accounting:audit-export', [
            'legal-entity' => $entity->uuid,
            'path' => 'exports/test.ndjson',
            '--disk' => 'audit-evidence',
            '--dataset' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exitCode, 'Export failed with output: '.$output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['valid']);
        $this->assertTrue(Storage::disk('audit-evidence')->exists('exports/test.ndjson'));
    }
}
