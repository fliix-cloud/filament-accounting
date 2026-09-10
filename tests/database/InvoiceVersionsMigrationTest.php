<?php

namespace FilamentAccounting\Tests\Database;

use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class InvoiceVersionsMigrationTest extends TestCase
{
    #[Test]
    public function existing_invoices_become_version_one_without_changing_the_number(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_10_000002_add_invoice_versions.php';
        $migration->down();
        $entity = $this->makeEntity();
        $invoice = Document::query()->create([
            'legal_entity_id' => $entity->getKey(), 'type' => DocumentType::SalesInvoice,
            'direction' => 'outgoing', 'currency' => 'EUR', 'number' => 'RE-EXISTING',
        ]);
        $migration->up();
        $this->assertSame(1, $invoice->fresh()->invoice_version);
        $this->assertSame('RE-EXISTING', $invoice->fresh()->number);

        $this->expectException(UniqueConstraintViolationException::class);
        Document::query()->create([
            'legal_entity_id' => $entity->getKey(), 'type' => DocumentType::SalesInvoice,
            'direction' => 'outgoing', 'currency' => 'EUR', 'number' => 'RE-EXISTING',
        ]);
    }

    #[Test]
    public function rollback_refuses_to_discard_existing_versions(): void
    {
        $entity = $this->makeEntity();
        $first = Document::query()->create([
            'legal_entity_id' => $entity->getKey(), 'type' => DocumentType::SalesInvoice,
            'direction' => 'outgoing', 'currency' => 'EUR', 'number' => 'RE-EXISTING',
        ]);
        Document::query()->create([
            'legal_entity_id' => $entity->getKey(), 'type' => DocumentType::SalesInvoice,
            'direction' => 'outgoing', 'currency' => 'EUR', 'number' => 'RE-EXISTING',
            'invoice_version' => 2, 'corrected_document_id' => $first->getKey(),
        ]);
        $migration = require __DIR__.'/../../database/migrations/2026_09_10_000002_add_invoice_versions.php';
        try {
            $migration->down();
            $this->fail('A rollback must not discard invoice history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invoice versions exist', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('accounting_documents', 'invoice_version'));
        $this->assertSame(2, Document::query()->count());
    }
}
