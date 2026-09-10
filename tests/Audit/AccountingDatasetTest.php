<?php

namespace FilamentAccounting\Tests\Audit;

use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Export\AccountingDatasetExporter;
use FilamentAccounting\Export\AccountingDatasetVerifier;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\PurchaseInvoiceIntakeStore;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

class AccountingDatasetTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    private function entity(): LegalEntity
    {
        Storage::fake('dataset');
        config()->set('filament-accounting.storage.disk', 'dataset');
        $this->actingAs($this->makeUser());

        return $this->makeEntity(['address_line1' => 'Street 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789']);
    }

    #[Test]
    public function export_links_invoice_journal_settlement_and_bank_and_verifies_without_database_access(): void
    {
        $other = $this->makeEntity(['legal_name' => 'EXCLUDED ENTITY']);
        $this->makeParty($other, ['legal_name' => 'EXCLUDED CUSTOMER']);
        $entity = $this->entity();
        $bank = $this->makeBankAccount($entity);
        DB::table('fints_bank_connections')->insert(['uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Bank', 'bank_code' => '12345678', 'endpoint_url' => 'https://example.invalid', 'username' => 'PRIVATE USER',
            'pin' => 'PRIVATE PIN', 'customer_id' => 'PRIVATE CUSTOMER ID', 'encrypted_fints_state' => 'PRIVATE STATE']);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-01', 'currency' => 'EUR',
            'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]]);
        app(ImportBankStatementLines::class)->handle($bank, [new BankStatementLineData('export-payment', 11900, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked')]);
        $line = BankStatementLine::query()->sole();
        app(AssignStatementLine::class)->handle($line, ['purpose' => SplitPurpose::SettleOpenItem->value, 'open_item_id' => $invoice->openItem->getKey()]);
        $package = app(AccountingDatasetExporter::class)->build($entity);
        $records = $package['dataset']['records'];
        $this->assertSame((string) $invoice->getKey(), $records['accounting_open_items'][0]['document_id']);
        $this->assertSame($records['accounting_open_items'][0]['id'], $records['accounting_settlements'][0]['open_item_id']);
        $this->assertSame($records['accounting_reconciliations'][0]['journal_entry_id'], $records['accounting_settlements'][0]['journal_entry_id']);
        $this->assertSame((string) $line->getKey(), $records['accounting_reconciliations'][0]['statement_line_id']);
        $json = app(CanonicalJson::class)->encode($package);
        foreach (['EXCLUDED ENTITY', 'EXCLUDED CUSTOMER', 'PRIVATE USER', 'PRIVATE PIN', 'PRIVATE CUSTOMER ID', 'PRIVATE STATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(1, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(AccountingDatasetVerifier::class)->verify($json);
        $this->assertTrue($result['valid']);
        $this->assertSame(36, $result['table_count']);
        $this->assertSame([], $queries);
        $this->assertFalse($result['export_event_anchored']);
    }

    #[Test]
    public function outgoing_and_incoming_originals_and_pending_intakes_are_portable_and_tampering_is_detected(): void
    {
        $entity = $this->entity();
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);
        app(IssueSalesInvoice::class)->handle($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'issue_date' => '2026-03-01', 'currency' => 'EUR',
            'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']]]);
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        app(ImportPurchaseInvoice::class)->handle($entity, 'original.pdf', $pdf);
        app(PurchaseInvoiceIntakeStore::class)->prepare($entity, ['primary' => ['filename' => 'pending.xml', 'contents' => '<Pending/>']]);
        $package = app(AccountingDatasetExporter::class)->build($entity);
        $this->assertCount(4, $package['dataset']['files']);
        $this->assertCount(1, $package['dataset']['pending']);
        $this->assertContains(base64_encode($pdf), array_column($package['dataset']['files'], 'contents_base64'));
        $this->assertCount(1, $package['dataset']['records']['accounting_invoice_artifact_sets']);
        foreach (['file', 'row', 'reference', 'table'] as $mutation) {
            $changed = $package;
            switch ($mutation) {
                case 'file':
                    $changed['dataset']['files'][0]['present'] = true;
                    $changed['dataset']['files'][0]['contents_base64'] = base64_encode('TAMPERED');
                    break;
                case 'row':
                    $changed['dataset']['records']['accounting_documents'][0]['gross_minor'] = '9';
                    break;
                case 'reference':
                    $changed['dataset']['records']['accounting_document_lines'][0]['document_id'] = '99999';
                    break;
                case 'table':
                    $changed['dataset']['records']['accounting_invoice_artifact_sets'] = [];
                    break;
            }
            $changed['dataset_sha256'] = hash('sha256', app(CanonicalJson::class)->encode($changed['dataset']));
            $result = app(AccountingDatasetVerifier::class)->verify(app(CanonicalJson::class)->encode($changed));
            $this->assertFalse($result['valid'], $mutation);
            $this->assertContains('dataset_commitment_mismatch', $result['issues']);
        }
    }

    #[Test]
    public function command_writes_private_dataset_and_auto_detects_it_offline_and_refuses_overwrite(): void
    {
        $entity = $this->entity();
        $args = ['legal-entity' => $entity->uuid, 'path' => 'exports/data.json', '--dataset' => true, '--legacy-json' => true, '--json' => true];
        $disk = Storage::disk('dataset');
        $anchorDisk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->once()->with('exports/data.json', \Mockery::type('string'), ['visibility' => 'private'])
            ->andReturnUsing(fn (string $path, string $bytes, array $options): bool => $disk->put($path, $bytes, $options));
        Storage::partialMock()->shouldReceive('disk')->with('dataset')->andReturn($proxy);
        Storage::shouldReceive('disk')->with('local')->andReturn($anchorDisk);
        $this->assertSame(0, Artisan::call('filament-accounting:audit-export', $args), Artisan::output());
        $original = Storage::disk('dataset')->get('exports/data.json');
        $this->assertSame(1, Artisan::call('filament-accounting:audit-export', $args));
        $this->assertSame($original, Storage::disk('dataset')->get('exports/data.json'));
        DB::table('accounting_audit_events')->delete();
        DB::table('accounting_audit_chain_heads')->delete();
        $this->assertSame(0, Artisan::call('filament-accounting:audit-verify-file', ['path' => 'exports/data.json', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['valid']);
    }

    #[Test]
    public function broken_cross_entity_references_abort_export_without_a_success_commitment(): void
    {
        $other = $this->makeEntity();
        $otherParty = $this->makeParty($other);
        $entity = $this->entity();
        $draft = app(IssueSalesInvoice::class)->createDraft($entity, ['party_id' => $this->makeParty($entity)->getKey(),
            'currency' => 'EUR', 'lines' => [['description' => 'Draft', 'quantity' => '1', 'unit_price_minor' => 10, 'tax_code' => 'DE-19']]]);
        DB::table('accounting_documents')->where('id', $draft->getKey())->update(['party_id' => $otherParty->getKey()]);
        try {
            app(AccountingDatasetExporter::class)->build($entity);
            $this->fail('Cross-entity references must block export.');
        } catch (AuditEvidenceException $exception) {
            $this->assertStringContainsString('dataset_reference_missing', $exception->getMessage());
        }
        $this->assertSame(0, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
    }

    #[Test]
    public function export_commitment_can_be_anchored_and_a_fully_rehashed_local_forgery_is_rejected(): void
    {
        $entity = $this->entity();
        Storage::fake('dataset-anchors');
        config()->set('filament-accounting.audit.anchor.disk', 'dataset-anchors');
        config()->set('filament-accounting.audit.anchor.immutable_storage_attested', true);
        $this->assertSame(0, Artisan::call('filament-accounting:audit-export', ['legal-entity' => $entity->uuid,
            'path' => 'anchored.json', '--dataset' => true, '--legacy-json' => true, '--anchor' => true, '--json' => true]), Artisan::output());
        $package = json_decode(Storage::disk('dataset')->get('anchored.json'), true, 512, JSON_THROW_ON_ERROR);
        $result = app(AccountingDatasetVerifier::class)->verify(app(CanonicalJson::class)->encode($package));
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['export_event_anchored']);
        $package['dataset']['records']['accounting_legal_entities'][0]['legal_name'] = 'Rewritten company';
        $hash = hash('sha256', app(CanonicalJson::class)->encode($package['dataset']));
        $package['dataset_sha256'] = $hash;
        $audit = &$package['audit_evidence'];
        $last = array_key_last($audit['audit_chain']['events']);
        $event = &$audit['audit_chain']['events'][$last];
        $payload = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        $payload['dataset_sha256'] = $hash;
        $event['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $event['canonical_payload'] = app(CanonicalJson::class)->encode($payload);
        $event['event_hash'] = app(AuditEventHasher::class)->hash($event);
        $audit['audit_chain']['head']['last_event_hash'] = $event['event_hash'];
        unset($audit['evidence_hash']);
        $audit['evidence_hash'] = hash('sha256', app(CanonicalJson::class)->encode($audit));
        $result = app(AccountingDatasetVerifier::class)->verify(app(CanonicalJson::class)->encode($package));
        $this->assertFalse($result['valid']);
        $this->assertContains('dataset_audit_evidence_invalid', $result['issues']);
    }

    #[Test]
    public function dataset_uses_the_accounting_connection_and_rejects_an_enclosing_transaction(): void
    {
        $this->makeEntity(['legal_name' => 'DEFAULT CONNECTION ONLY']);
        config()->set('database.connections.dataset_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('dataset_accounting')->getSchemaBuilder());
        try {
            foreach (['2026_08_30_000001_create_filament_accounting_tables', '2026_08_31_000002_create_accounting_party_bank_accounts',
                '2026_09_01_000003_create_filament_accounting_banking_tables', '2026_09_04_000004_add_tax_rule_to_reconciliation_splits', '2026_09_04_000005_add_party_contact_columns'] as $migration) {
                (require __DIR__.'/../../database/migrations/'.$migration.'.php')->up();
            }
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'dataset_accounting');
        $entity = $this->entity();
        $package = app(AccountingDatasetExporter::class)->build($entity);
        $this->assertStringNotContainsString('DEFAULT CONNECTION ONLY', app(CanonicalJson::class)->encode($package));
        $this->assertSame(0, DB::connection('sqlite')->table('accounting_audit_events')->where('operation', 'accounting_export.prepared')->count());
        $this->assertSame(1, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
        $this->expectExceptionMessage('independent accounting transaction');
        $entity->getConnection()->transaction(fn () => app(AccountingDatasetExporter::class)->build($entity));
    }

    #[Test]
    public function unsafe_destinations_and_invalid_options_are_rejected_before_recording_an_export(): void
    {
        $entity = $this->entity();
        foreach (['/absolute.json', 'C:\\absolute.json', '../outside.json', "bad\0name.json"] as $path) {
            $this->assertSame(1, Artisan::call('filament-accounting:audit-export', [
                'legal-entity' => $entity->uuid, 'path' => $path, '--dataset' => true, '--json' => true]));
        }
        $this->assertSame(1, Artisan::call('filament-accounting:audit-export', [
            'legal-entity' => $entity->uuid, 'path' => 'public.json', '--dataset' => true, '--disk' => 'public', '--json' => true]));
        $this->assertSame(1, Artisan::call('filament-accounting:audit-export', [
            'legal-entity' => $entity->uuid, 'path' => 'invalid.json', '--anchor' => true, '--json' => true]));
        $this->assertSame(0, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
    }

    #[Test]
    public function missing_preserved_original_blocks_export_and_no_package_is_written(): void
    {
        $entity = $this->entity();
        $result = app(ImportPurchaseInvoice::class)->handle($entity, 'original.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $attachment = $result->document->attachments()->sole();
        Storage::disk($attachment->disk)->delete($attachment->path);
        $this->assertSame(1, Artisan::call('filament-accounting:audit-export', ['legal-entity' => $entity->uuid, 'path' => 'failed.json', '--dataset' => true, '--json' => true]));
        Storage::disk('dataset')->assertMissing('failed.json');
        $this->assertSame(0, AuditEvent::query()->where('operation', 'accounting_export.prepared')->count());
    }
}
