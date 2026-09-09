<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\PurchaseInvoiceIntakeStore;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class InvoiceEvidenceTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    /** @return array<string, array{string}> */
    public static function mutations(): array
    {
        return array_combine($names = ['file_changed', 'file_missing', 'manifest_rewritten', 'preservation_reset', 'intake_deleted', 'entity_changed'], array_map(fn (string $name): array => [$name], $names));
    }

    #[Test]
    #[DataProvider('mutations')]
    public function scheduled_check_detects_intake_tampering_without_a_user_or_a_retry(string $mutation): void
    {
        Storage::fake('invoice-evidence');
        config()->set('filament-accounting.storage.disk', 'invoice-evidence');
        $entity = $this->makeEntity();
        $other = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $inputs = ['primary' => ['filename' => 'invoice.xml', 'contents' => '<Invoice/>']];
        $store = app(PurchaseInvoiceIntakeStore::class);
        $intake = $store->prepare($other, $inputs);
        $store->preserve($other, $intake, $inputs);
        $intake->refresh();
        $row = DB::table('accounting_purchase_invoice_intakes')->where('id', $intake->getKey());
        switch ($mutation) {
            case 'file_changed':
                Storage::disk($intake->disk)->put($intake->files['primary']['path'], 'tampered');
                break;
            case 'file_missing':
                Storage::disk($intake->disk)->delete($intake->files['primary']['path']);
                break;
            case 'manifest_rewritten':
                $files = $intake->files;
                $files['primary']['filename'] = 'renamed.xml';
                $row->update(['files' => json_encode($files)]);
                break;
            case 'preservation_reset':
                $row->update(['preserved_files' => '[]', 'preserved_at' => null]);
                Storage::disk($intake->disk)->delete($intake->files['primary']['path']);
                break;
            case 'intake_deleted':
                $row->delete();
                break;
            case 'entity_changed':
                $row->update(['legal_entity_id' => $entity->getKey()]);
                break;
        }
        auth()->forgetGuards();
        $this->assertTrue(app(AuditChainVerifier::class)->verify((int) $other->getKey())->isValid());
        $eventCount = AuditEvent::query()->count();
        $files = Storage::disk($intake->disk)->allFiles();
        $this->assertSame(1, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $report['schema_version']);
        $this->assertFalse($report['valid']);
        $otherReport = collect($report['legal_entities'])->firstWhere('legal_entity_id', $other->getKey());
        $this->assertNotEmpty($otherReport['invoice_evidence']['issues']);
        $this->assertSame($eventCount, AuditEvent::query()->count());
        $this->assertSame($files, Storage::disk($intake->disk)->allFiles());
    }

    #[Test]
    public function completed_import_is_checked_in_both_directions_against_its_original(): void
    {
        Storage::fake('invoice-evidence');
        config()->set('filament-accounting.storage.disk', 'invoice-evidence');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'invoice.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $report = app(InvoiceEvidenceVerifier::class)->verify((int) $entity->getKey());
        $this->assertSame([], $report['issues']);
        $this->assertSame([], $report['pending']);
        DB::table('accounting_documents')->where('id', $result->document->getKey())->update(['e_invoice_meta' => '{}']);
        $this->assertNotEmpty(app(InvoiceEvidenceVerifier::class)->verify((int) $entity->getKey())['issues']);
        DB::table('accounting_documents')->where('id', $result->document->getKey())
            ->update(['e_invoice_meta' => json_encode($result->document->e_invoice_meta)]);
        DB::table('accounting_attachments')->delete();
        $this->assertNotEmpty(app(InvoiceEvidenceVerifier::class)->verify((int) $entity->getKey())['issues']);
    }

    #[Test]
    public function pending_and_rejected_imports_are_reported_without_being_called_corrupt(): void
    {
        Storage::fake('invoice-evidence');
        config()->set('filament-accounting.storage.disk', 'invoice-evidence');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $store = app(PurchaseInvoiceIntakeStore::class);
        $store->prepare($entity, ['primary' => ['filename' => 'pending.xml', 'contents' => '<Pending/>']]);
        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'rejected.xml', '<not-an-invoice/>');
            $this->fail('Unsupported XML should remain blocked.');
        } catch (DocumentException) {
        }
        auth()->forgetGuards();
        $this->assertSame(0, Artisan::call('filament-accounting:verify', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $invoices = $report['legal_entities'][0]['invoice_evidence'];
        $this->assertSame([], $invoices['issues']);
        $this->assertSame(2, $invoices['intake_count']);
        $this->assertCount(2, $invoices['pending']);
        $this->assertSame(['pending', 'blocked'], array_column($invoices['pending'], 'status'));
        $this->assertSame(0, Artisan::call('filament-accounting:verify'));
        $this->assertStringContainsString('Pending [purchase_intake_open]', Artisan::output());
        $this->assertSame($invoices, app(InvoiceEvidenceVerifier::class)->verify((int) $entity->getKey()));
    }
}
