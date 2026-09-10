<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Export\DatasetInspection;
use FilamentAccounting\Export\StreamDatasetExporter;
use FilamentAccounting\Export\StreamDatasetVerifier;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;

class StreamDatasetTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    private function entity(): LegalEntity
    {
        Storage::fake('stream-dataset');
        Storage::fake('stream-anchors');
        config()->set('filament-accounting.storage.disk', 'stream-dataset');
        config()->set('filament-accounting.audit.anchor.disk', 'stream-anchors');
        $this->actingAs($this->makeUser());

        return $this->makeEntity(['address_line1' => 'Street 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789']);
    }

    #[Test]
    public function independent_inspection_reconstructs_journal_totals_links_and_originals_after_source_loss(): void
    {
        $entity = $this->entity();
        $invoice = app(IssueSalesInvoice::class)->handle($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-01', 'currency' => 'EUR',
            'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]]);
        $bank = $this->makeBankAccount($entity);
        app(ImportBankStatementLines::class)->handle($bank, [new BankStatementLineData('stream-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked')]);
        app(AssignStatementLine::class)->handle(BankStatementLine::query()->sole(), ['purpose' => SplitPurpose::SettleOpenItem->value, 'open_item_id' => $invoice->openItem->getKey()]);
        $pdf = "%PDF-1.4\n".str_repeat('retained original ', 5000)."\n%%EOF";
        app(ImportPurchaseInvoice::class)->handle($entity, 'original.pdf', $pdf);
        $built = app(StreamDatasetExporter::class)->build($entity);
        $this->assertSame(2, $built['report']['schema_version']);
        $this->assertSame(36, $built['report']['table_count']);
        DB::table('accounting_audit_events')->delete();
        DB::table('accounting_audit_chain_heads')->delete();
        foreach (Storage::disk('stream-dataset')->allFiles() as $path) {
            Storage::disk('stream-dataset')->delete($path);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $inspected = false;
        try {
            $report = app(StreamDatasetVerifier::class)->verify($built['stream'], function (DatasetInspection $inspection) use ($pdf, &$inspected): void {
                $inspected = true;
                // Independently query the imported snapshot, not exporter totals.
                $totals = $inspection->database->query('SELECT SUM(CAST(base_debit_minor AS INTEGER)) AS debit, SUM(CAST(base_credit_minor AS INTEGER)) AS credit FROM accounting_journal_lines')->fetch(PDO::FETCH_ASSOC);
                $this->assertSame(23800, (int) $totals['debit']);
                $this->assertSame(23800, (int) $totals['credit']);
                $linked = $inspection->database->query('SELECT d.number FROM accounting_documents d JOIN accounting_open_items o ON o.document_id=d.id JOIN accounting_settlements s ON s.open_item_id=o.id JOIN accounting_reconciliations r ON r.journal_entry_id=s.journal_entry_id JOIN accounting_bank_statement_lines b ON b.id=r.statement_line_id WHERE b.external_id="stream-payment"')->fetchColumn();
                $this->assertNotFalse($linked);
                $hash = hash_init('sha256');
                $bytes = 0;
                $chunks = $inspection->database->query('SELECT bytes FROM file_chunks ORDER BY ordinal');
                while (($chunk = $chunks->fetchColumn()) !== false) {
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                }
                $this->assertSame(strlen($pdf), $bytes);
                $this->assertSame(hash('sha256', $pdf), hash_final($hash));
            });
            $this->assertTrue($report['valid']);
            $this->assertTrue($inspected);
            $this->assertSame([], $queries);
        } finally {
            fclose($built['stream']);
        }
    }

    #[Test]
    public function many_original_files_do_not_accumulate_in_php_memory(): void
    {
        $entity = $this->entity();
        $draft = app(IssueSalesInvoice::class)->createDraft($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'currency' => 'EUR', 'lines' => [['description' => 'Draft', 'quantity' => '1', 'unit_price_minor' => 10, 'tax_code' => 'DE-19']]]);
        for ($i = 0; $i < 48; $i++) {
            $bytes = "%PDF-1.4\n".$i.str_repeat('a', 1024 * 1024)."\n%%EOF";
            $path = 'originals/'.$i.'.pdf';
            Storage::disk('stream-dataset')->put($path, $bytes);
            DB::table('accounting_attachments')->insert(['uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->getKey(),
                'attachable_type' => $draft->getMorphClass(), 'attachable_id' => $draft->getKey(), 'original_filename' => $i.'.pdf',
                'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'disk' => 'stream-dataset', 'path' => $path]);
        }
        unset($bytes);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $built = app(StreamDatasetExporter::class)->build($entity);
        try {
            $this->assertSame(48, $built['report']['file_count']);
            $this->assertGreaterThan(64 * 1024 * 1024, fstat($built['stream'])['size']);
            $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        } finally {
            fclose($built['stream']);
        }
    }

    #[Test]
    public function streaming_command_writes_and_reads_back_a_verified_package(): void
    {
        $entity = $this->entity();
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
        $code = Artisan::call('filament-accounting:audit-export', ['legal-entity' => $entity->uuid, 'path' => 'data.ndjson', '--dataset' => true, '--anchor' => true, '--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $report['dataset']['schema_version']);
        $this->assertTrue($report['dataset']['export_event_anchored']);
        $code = Artisan::call('filament-accounting:audit-verify-file', ['path' => 'data.ndjson', '--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $offline = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($report['dataset']['package_sha256'], $offline['package_sha256']);
    }

    #[Test]
    public function truncated_changed_and_trailing_input_never_reaches_the_inspection_callback(): void
    {
        $entity = $this->entity();
        $built = app(StreamDatasetExporter::class)->build($entity);
        $original = stream_get_contents($built['stream']);
        fclose($built['stream']);
        foreach ([substr($original, 0, -20), $original."{}\n", str_replace('Demo GmbH', 'Changed GmbH', $original)] as $changed) {
            $stream = tmpfile();
            fwrite($stream, $changed);
            rewind($stream);
            try {
                app(StreamDatasetVerifier::class)->verify($stream, function (): void {
                    $this->fail('Invalid package was imported.');
                });
                $this->fail('Invalid package was accepted.');
            } catch (AuditEvidenceException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } finally {
                fclose($stream);
            }
        }
    }

    #[Test]
    public function interrupted_issuance_and_blocked_import_survive_streaming_and_missing_anchor_is_rejected(): void
    {
        $entity = $this->entity();
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
        Attachment::creating(function (Attachment $attachment): void {
            if ($attachment->source_type === 'generated_pdf') {
                throw new \RuntimeException('Interrupted PDF metadata write');
            }
        });
        try {
            app(IssueSalesInvoice::class)->handle($entity, ['party_id' => $this->makeParty($entity)->getKey(),
                'issue_date' => '2026-03-01', 'currency' => 'EUR', 'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]]);
            $this->fail('Expected interrupted issuance.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Interrupted PDF metadata write', $exception->getMessage());
        }
        try {
            app(ImportPurchaseInvoice::class)->handle($entity, 'blocked.xml', '<unsupported/>');
            $this->fail('Expected blocked XML.');
        } catch (DocumentException) {
        }
        $built = app(StreamDatasetExporter::class)->build($entity, true);
        try {
            $this->assertSame(2, $built['report']['pending_count']);
            $this->assertSame(3, $built['report']['file_count']);
            $this->assertTrue($built['report']['export_event_anchored']);
            $report = app(StreamDatasetVerifier::class)->verify($built['stream'], function (DatasetInspection $index): void {
                $set = $index->database->query('SELECT * FROM accounting_invoice_artifact_sets')->fetch(PDO::FETCH_ASSOC);
                $this->assertNull($set['completed_at']);
                $this->assertStringStartsWith('%PDF-', base64_decode($set['pdf_base64'], true));
                $this->assertSame('blocked', $index->database->query('SELECT status FROM accounting_purchase_invoice_intakes')->fetchColumn());
            });
            $this->assertTrue($report['valid']);
            rewind($built['stream']);
            $small = stream_get_contents($built['stream']);
            $lines = explode("\n", rtrim($small, "\n"));
            $footer = json_decode(array_pop($lines), true, 512, JSON_THROW_ON_ERROR);
            $footer['anchors'] = [];
            $lines[] = json_encode($footer, JSON_THROW_ON_ERROR);
            $changed = tmpfile();
            fwrite($changed, implode("\n", $lines)."\n");
            rewind($changed);
            try {
                $this->expectExceptionMessage('external anchor invalid or missing');
                app(StreamDatasetVerifier::class)->verify($changed);
            } finally {
                fclose($changed);
            }
        } finally {
            fclose($built['stream']);
        }
    }

    #[Test]
    public function corrupted_journal_blocks_streaming_before_export_commitment(): void
    {
        $entity = $this->entity();
        app(IssueSalesInvoice::class)->handle($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-01', 'currency' => 'EUR', 'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]]);
        DB::table('accounting_journal_lines')->update(['description' => 'Changed']);
        try {
            app(StreamDatasetExporter::class)->build($entity);
            $this->fail('Changed journal must block export.');
        } catch (AuditEvidenceException $exception) {
            $this->assertStringContainsString('Journal integrity failed', $exception->getMessage());
        }
        $this->assertSame(0, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
    }
}
