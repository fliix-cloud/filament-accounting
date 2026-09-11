<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class StorageIntegrityTest extends TestCase
{
    #[Test]
    public function reports_missing_attachment_files(): void
    {
        Storage::fake('integrity-test');
        config()->set('filament-accounting.storage.disk', 'integrity-test');

        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $attachment = new Attachment;
        $attachment->fill([
            'legal_entity_id' => $entity->getKey(),
            'attachable_type' => $entity->getMorphClass(),
            'attachable_id' => $entity->getKey(),
            'original_filename' => 'missing.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'sha256' => hash('sha256', 'test'),
            'disk' => 'integrity-test',
            'path' => 'accounting/attachments/missing.pdf',
            'source_type' => 'upload',
        ]);
        $attachment->save();

        $exitCode = Artisan::call('filament-accounting:storage-integrity', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertGreaterThan(0, $report['issue_count']);
        $this->assertSame('attachment_missing', $report['issues'][0]['type']);
        $this->assertSame($attachment->getKey(), $report['issues'][0]['attachment_id']);
    }

    #[Test]
    public function clean_storage_reports_no_issues(): void
    {
        Storage::fake('integrity-clean');
        config()->set('filament-accounting.storage.disk', 'integrity-clean');

        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        Storage::disk('integrity-clean')->put('accounting/attachments/present.pdf', '%PDF-mock');
        $attachment = new Attachment;
        $attachment->fill([
            'legal_entity_id' => $entity->getKey(),
            'attachable_type' => $entity->getMorphClass(),
            'attachable_id' => $entity->getKey(),
            'original_filename' => 'present.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
            'sha256' => hash('sha256', '%PDF-mock'),
            'disk' => 'integrity-clean',
            'path' => 'accounting/attachments/present.pdf',
            'source_type' => 'upload',
        ]);
        $attachment->save();

        $exitCode = Artisan::call('filament-accounting:storage-integrity', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $report['issue_count']);
    }

    #[Test]
    public function scan_disk_finds_orphaned_files(): void
    {
        Storage::fake('integrity-orphan');
        config()->set('filament-accounting.storage.disk', 'integrity-orphan');

        $this->makeEntity();
        Storage::disk('integrity-orphan')->put('accounting/attachments/orphan.pdf', 'orphan');

        $exitCode = Artisan::call('filament-accounting:storage-integrity', ['--scan-disk' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertGreaterThan(0, $report['orphaned_file_count']);
        $orphans = array_filter($report['issues'], fn ($i) => $i['type'] === 'orphaned_file');
        $this->assertCount(1, $orphans);
    }
}