<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\AuditChainCompromisedException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\EntityIsolationException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\DeletePurchaseInvoiceDraft;
use FilamentAccounting\Services\PostDocument;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Services\StoreAttachment;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class RecordProtectionTest extends TestCase
{
    #[Test]
    public function discard_keeps_original_bytes_lines_and_actor_reason_history(): void
    {
        Storage::fake('local');
        $entity = $this->makeEntity();
        $user = $this->makeUser();
        $this->actingAs($user);
        $document = $this->draft($entity);
        $contents = '%PDF-1.7'.PHP_EOL.'original';
        $attachment = app(StoreAttachment::class)->handle($entity, $document, 'invoice.pdf', $contents, 'original_invoice');
        $line = $this->line($document);

        app(DeletePurchaseInvoiceDraft::class)->handle($document, '  Duplicate upload  ');

        $this->assertSame(DocumentStatus::Discarded, $document->fresh()->document_status);
        $this->assertSame(PostingStatus::Unposted, $document->fresh()->posting_status);
        $this->assertNotNull($line->fresh());
        $this->assertSame($contents, app(ReadAttachment::class)->handle($attachment->fresh()));
        $event = AuditEvent::query()->where('operation', 'document.purchase_draft_discarded')->sole();
        $this->assertSame('Duplicate upload', $event->reason);
        $this->assertSame((string) $user->getKey(), $event->actor_id);
        $this->assertSame('draft', $event->payload['before']);
        $this->assertSame('discarded', $event->payload['after']);
        $this->assertSame($attachment->sha256, $event->payload['attachments'][0]['sha256']);
    }

    #[Test]
    public function discard_rejects_blank_reasons_and_stale_received_or_posted_drafts(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        foreach (['blank', 'received', 'posted'] as $case) {
            $draft = $this->draft($entity);
            if ($case === 'received') {
                $draft->fresh()->update(['document_status' => DocumentStatus::Received]);
            } elseif ($case === 'posted') {
                // Simulate a legacy inconsistent record; the discard service must still refuse it.
                Document::query()->whereKey($draft->getKey())->update(['posting_status' => PostingStatus::Posted]);
            }
            try {
                app(DeletePurchaseInvoiceDraft::class)->handle($draft, $case === 'blank' ? " \n " : 'Duplicate');
                $this->fail('Invalid discard must fail.');
            } catch (DocumentException) {
                $this->assertNotSame(DocumentStatus::Discarded, $draft->fresh()->document_status);
            }
        }
        $this->assertDatabaseCount('accounting_audit_events', 0);
    }

    #[Test]
    public function discard_rechecks_entity_from_storage_and_rolls_back_if_audit_fails(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $draft = $this->draft($entity);
        $other = $this->makeEntity(['legal_name' => 'Other GmbH']);
        $draft->legal_entity_id = $other->getKey();
        try {
            app(DeletePurchaseInvoiceDraft::class)->handle($draft, 'Wrong tenant');
            $this->fail('An in-memory owner change must not bypass scope.');
        } catch (EntityIsolationException) {
            $this->assertSame(DocumentStatus::Draft, $draft->fresh()->document_status);
        }

        $draft = $this->draft($other);
        $other->getConnection()->table('accounting_audit_chain_heads')->insert([
            'legal_entity_id' => $other->getKey(),
            'last_sequence' => 1,
            'last_event_hash' => str_repeat('0', 64),
            'event_count' => 1,
            'updated_at' => now(),
        ]);
        try {
            app(DeletePurchaseInvoiceDraft::class)->handle($draft, 'Audit unavailable');
            $this->fail('Audit failure must roll back the state change.');
        } catch (AuditChainCompromisedException) {
            $this->assertSame(DocumentStatus::Draft, $draft->fresh()->document_status);
        }
    }

    #[Test]
    public function finalized_documents_reject_downgrades_reassignment_and_stale_edits(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $draft = $this->draft($entity);
        $stale = $draft->fresh();
        $draft->update(['document_status' => DocumentStatus::Received]);

        foreach ([
            fn () => $draft->fresh()->update(['document_status' => DocumentStatus::Draft]),
            fn () => $draft->fresh()->update(['legal_entity_id' => 999]),
            fn () => $draft->fresh()->update(['type' => DocumentType::SalesInvoice]),
            fn () => $stale->update(['gross_minor' => 1]),
            fn () => $stale->delete(),
        ] as $operation) {
            $this->assertImmutable($operation);
        }
        $this->assertSame(DocumentStatus::Received, $draft->fresh()->document_status);
        $this->assertSame(0, $draft->fresh()->gross_minor);
    }

    #[Test]
    public function lines_reject_reparenting_and_ignore_cached_draft_relations(): void
    {
        $entity = $this->makeEntity();
        $draft = $this->draft($entity);
        $other = $this->draft($entity);
        $line = $this->line($draft);
        $line->load('document');
        $draft->update(['document_status' => DocumentStatus::Received]);

        $this->assertImmutable(fn () => $line->update(['description' => 'Changed']));
        $this->assertImmutable(fn () => $line->fresh()->update(['document_id' => $other->getKey()]));
        $this->assertImmutable(fn () => $line->delete());
        $this->assertSame('Original line', $line->fresh()->description);
    }

    #[Test]
    public function original_attachment_metadata_and_deletion_are_protected(): void
    {
        Storage::fake('local');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $draft = $this->draft($entity);
        foreach (['original_invoice', 'embedded_e_invoice', 'supplied_e_invoice'] as $source) {
            $attachment = app(StoreAttachment::class)->handle($entity, $draft, 'invoice.xml', '<?xml version="1.0"?><Invoice/>', $source);
            $this->assertImmutable(fn () => $attachment->update(['sha256' => str_repeat('0', 64)]));
            $attachment->source_type = 'generated_xml';
            $this->assertImmutable(fn () => $attachment->delete());
            Storage::disk('local')->assertExists($attachment->path);
        }
        $this->assertImmutable(fn () => $draft->delete());
    }

    #[Test]
    public function discarded_and_draft_documents_cannot_be_posted_or_reactivated(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $draft = $this->draft($entity);
        $discarded = $this->draft($entity);
        app(DeletePurchaseInvoiceDraft::class)->handle($discarded, 'Duplicate');

        foreach ([$draft, $discarded] as $document) {
            try {
                app(PostDocument::class)->handle($document);
                $this->fail('An unfinished or discarded document must not be posted.');
            } catch (DocumentException) {
                $this->assertSame(PostingStatus::Unposted, $document->fresh()->posting_status);
            }
        }
        $this->assertImmutable(fn () => $discarded->fresh()->update(['document_status' => DocumentStatus::Draft]));
        $this->assertDatabaseCount('accounting_journal_entries', 0);
    }

    private function draft(LegalEntity $entity): Document
    {
        return Document::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => DocumentType::PurchaseInvoice,
            'direction' => DocumentDirection::Incoming,
            'document_status' => DocumentStatus::Draft,
            'posting_status' => PostingStatus::Unposted,
            'currency' => 'EUR',
        ])->fresh();
    }

    private function line(Document $document): DocumentLine
    {
        return $document->lines()->create([
            'position' => 1,
            'description' => 'Original line',
            'quantity' => '1',
            'unit_price_minor' => 100,
            'net_minor' => 100,
            'tax_minor' => 0,
            'gross_minor' => 100,
        ]);
    }

    private function assertImmutable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Retained accounting evidence must be immutable.');
        } catch (PostedRecordImmutableException) {
            $this->addToAssertionCount(1);
        }
    }
}
