<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class SalesInvoiceCorrectionTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function correction_preserves_original_evidence_and_reverses_the_old_posting_exactly_once(): void
    {
        $original = $this->invoice();
        $originalAttributes = $original->getAttributes();
        $originalFiles = $original->attachments()->get();
        $originalJournal = JournalEntry::query()->where('source_type', 'document')->sole();
        $issuer = app(IssueSalesInvoice::class);
        $draft = $issuer->correct($original, $this->payload($original, '2'), 'Quantity correction');
        $this->assertSame(DocumentStatus::Draft, $draft->document_status);
        $this->assertFalse($original->openItem->is_reversed);
        $correction = $issuer->issue($draft);
        $issuer->issue($correction);

        $this->assertNotSame($original->number, $correction->number);
        $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
        $this->assertSame('1', $original->fresh()->lines->sole()->quantity);
        $this->assertTrue($original->fresh()->openItem->is_reversed);
        $this->assertFalse($correction->openItem->is_reversed);
        $this->assertSame(23800, $correction->openItem->original_minor);
        $this->assertSame(3, JournalEntry::query()->count());
        $reversal = JournalEntry::query()->where('reverses_id', $originalJournal->getKey())->sole();
        $this->assertSame($originalJournal->lines->sum('debit_minor'), $reversal->lines->sum('credit_minor'));
        $this->assertSame('Quantity correction', AuditEvent::query()->where('operation', 'document.corrected')->sole()->reason);
        foreach ($originalFiles as $file) {
            $this->assertSame($file->sha256, hash('sha256', Storage::disk($file->disk)->get($file->path)));
        }
        $this->assertSame(2, InvoiceArtifactSet::query()->count());
        $set = $correction->artifactSet;
        $this->assertStringContainsString($original->number, $set->xml);
        $this->assertStringContainsString('<ram:TypeCode>384</ram:TypeCode>', $set->xml);
        $this->assertStringContainsString('Quantity correction', $set->xml);
        app(GenerateInvoiceArtifacts::class)->handle($correction);
        $this->assertSame(2, InvoiceArtifactSet::query()->count());
        $this->assertTrue(app(AuditChainVerifier::class)->verify($original->legal_entity_id)->isValid());
        $this->assertSame([], app(InvoiceEvidenceVerifier::class)->verify($original->legal_entity_id)['issues']);
    }

    #[Test]
    public function correction_requires_a_reason_and_does_not_allow_parallel_replacements(): void
    {
        $original = $this->invoice();
        $issuer = app(IssueSalesInvoice::class);
        try {
            $issuer->correct($original, $this->payload($original), '   ');
            $this->fail('A reason is required.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::errors.reason_required'), $exception->getMessage());
        }
        $this->assertSame(1, Document::query()->count());
        $issuer->correct($original, $this->payload($original), 'Correction');
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.invoice_correction_exists'));
        $issuer->correct($original, $this->payload($original), 'Second correction');
    }

    #[Test]
    public function a_payment_allocated_after_editing_blocks_issuance_before_any_reversal(): void
    {
        $original = $this->invoice();
        $issuer = app(IssueSalesInvoice::class);
        $draft = $issuer->correct($original, $this->payload($original), 'Correction');
        Settlement::query()->create([
            'legal_entity_id' => $original->legal_entity_id, 'open_item_id' => $original->openItem->getKey(),
            'journal_entry_id' => JournalEntry::query()->sole()->getKey(),
            'amount_minor' => 100, 'currency' => 'EUR', 'is_reversed' => false,
        ]);
        try {
            $issuer->issue($draft);
            $this->fail('An allocated payment must block correction.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::errors.invoice_correction_has_settlements'), $exception->getMessage());
        }
        $this->assertSame(DocumentStatus::Draft, $draft->fresh()->document_status);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertFalse($original->fresh()->openItem->is_reversed);
    }

    #[Test]
    public function invalid_decimal_input_rolls_back_the_entire_draft_update(): void
    {
        $original = $this->invoice();
        $issuer = app(IssueSalesInvoice::class);
        $draft = $issuer->correct($original, $this->payload($original), 'Correction');
        $payload = $this->payload($original);
        $payload['lines'][0]['unit_price'] = '1.234,56';
        try {
            $issuer->updateDraft($draft, $payload);
            $this->fail('Ambiguous decimal input must be rejected.');
        } catch (InvalidMoneyException) {
            $this->assertSame(10000, $draft->fresh()->lines->sole()->unit_price_minor);
        }
    }

    #[Test]
    public function correction_requires_issuance_permission_at_the_service_boundary(): void
    {
        $original = $this->invoice();
        Gate::define(config('filament-accounting.authorization.abilities.issue_invoices'), fn (): bool => false);
        $this->expectException(AuthorizationException::class);
        app(IssueSalesInvoice::class)->correct($original, $this->payload($original), 'Correction');
    }

    private function invoice(): Document
    {
        Storage::fake('local');
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789',
        ]);
        $this->actingAs($this->makeUser());
        config()->set('filament-accounting.e_invoice.generate_on_issue', true);

        return app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $this->makeParty($entity)->getKey(), 'issue_date' => '2026-09-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'DE-19']],
        ]);
    }

    private function payload(Document $document, string $quantity = '1'): array
    {
        return [
            'party_id' => $document->party_id, 'issue_date' => '2026-09-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => $quantity, 'unit_price' => '100.00', 'tax_code' => 'DE-19']],
        ];
    }
}
