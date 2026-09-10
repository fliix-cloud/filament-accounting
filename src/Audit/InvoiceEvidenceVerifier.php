<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\InvoiceArtifactSet;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\VerifyPurchaseInvoiceOriginals;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class InvoiceEvidenceVerifier
{
    public function __construct(
        private readonly CanonicalJson $canonical,
        private readonly GenerateInvoiceArtifacts $artifacts,
        private readonly VerifyPurchaseInvoiceOriginals $originals,
    ) {}

    /**
     * Caller holds the entity lock and separately verifies the audit chain and anchors.
     * Pending processing is reported separately from evidence integrity failures.
     *
     * @return array{intake_count: int, artifact_set_count: int, issues: list<array<string, mixed>>, pending: list<array<string, mixed>>}
     */
    public function verify(int $legalEntityId, ?\Closure $onPending = null): array
    {
        $intakes = PurchaseInvoiceIntake::query()->where('legal_entity_id', $legalEntityId);
        $sets = InvoiceArtifactSet::query()->where('legal_entity_id', $legalEntityId);
        $hasSet = fn ($id): bool => (clone $sets)->where('document_id', $id)->exists();
        $events = AuditEvent::query()->where('legal_entity_id', $legalEntityId)
            ->whereIn('operation', ['purchase_intake.created', 'purchase_intake.file_preserved', 'purchase_intake.completed', 'invoice_artifacts.prepared'])
            ->orderBy('sequence')->lazy(100);
        $issues = [];
        $pending = [];
        $emit = function (array $item) use (&$pending, $onPending): void {
            if ($onPending !== null) {
                $onPending($item);
            } else {
                $pending[] = $item;
            }
        };

        foreach ($events as $event) {
            $isIntake = str_starts_with($event->operation, 'purchase_intake.');
            $targetExists = $isIntake ? (clone $intakes)->whereKey($event->target_id)->exists() : $hasSet($event->target_id);
            $type = $isIntake ? (new PurchaseInvoiceIntake)->getMorphClass() : (new Document)->getMorphClass();
            if ($event->target_type !== $type || ! $targetExists) {
                $issues[] = ['code' => 'invoice_evidence_target_missing', 'message' => 'Invoice evidence no longer resolves within this entity.',
                    'sequence' => $event->sequence, 'target_type' => $event->target_type, 'target_id' => $event->target_id];
            }
        }

        foreach ((clone $intakes)->lazyById(1) as $intake) {
            try {
                $this->verifyIntake($intake);
            } catch (Throwable) {
                $issues[] = $this->item('purchase_intake_integrity_failed', 'Purchase intake evidence or original files failed verification.', 'intake', (int) $intake->getKey());
            }
            if ($intake->status !== 'complete') {
                $emit($this->item('purchase_intake_open', 'Purchase import requires review or completion.', 'intake', (int) $intake->getKey()) + ['status' => $intake->status]);
            }
        }

        // Staged PDFs can be large; do not load all sets into memory at once.
        foreach ((clone $sets)->lazyById(1) as $set) {
            try {
                $document = Document::query()->where('legal_entity_id', $legalEntityId)->findOrFail($set->document_id);
                $this->artifacts->verifyPreservedSet($document, $set);
                if ($set->completed_at === null && $document->posting_status !== PostingStatus::Unposted) {
                    throw new RuntimeException('Posted invoice has an incomplete artifact set.');
                }
            } catch (Throwable) {
                $issues[] = $this->item('invoice_artifact_integrity_failed', 'Outgoing invoice evidence or generated files failed verification.', 'document', $set->document_id);
            }
            if ($set->completed_at === null) {
                $emit($this->item('invoice_artifacts_pending', 'Outgoing invoice files require completion.', 'document', $set->document_id));
            }
        }

        // Reverse document links prevent a removed intake or artifact set from
        // disappearing simply because it is absent from the two primary queries.
        foreach (Document::query()->where('legal_entity_id', $legalEntityId)->lazyById(100) as $document) {
            $intakeId = data_get($document->e_invoice_meta, 'intake_id');
            if ($intakeId !== null && ! (clone $intakes)->whereKey($intakeId)->where('document_id', $document->getKey())->exists()) {
                $issues[] = $this->item('invoice_intake_link_invalid', 'Imported invoice has no matching intake in this entity.', 'document', (int) $document->getKey());
            }
            if ($document->type === DocumentType::SalesInvoice && $document->document_status === DocumentStatus::Issued
                && data_get($document->e_invoice_meta, 'artifacts_required', false) && ! $hasSet($document->getKey())) {
                $item = $this->item('invoice_artifacts_missing', 'Issued invoice requires an artifact set.', 'document', (int) $document->getKey());
                if ($document->posting_status === PostingStatus::Unposted) {
                    $emit($item);
                } else {
                    $issues[] = $item;
                }
            }
        }

        foreach (Attachment::query()->where('legal_entity_id', $legalEntityId)
            ->whereIn('source_type', ['generated_pdf', 'generated_xml'])->lazyById(100) as $attachment) {
            if ($attachment->attachable_type !== (new Document)->getMorphClass() || ! $hasSet($attachment->attachable_id)) {
                $issues[] = $this->item('invoice_artifact_set_missing', 'Generated attachment has no authoritative artifact set.', 'attachment', (int) $attachment->getKey());
            }
        }

        return ['intake_count' => $intakes->count(), 'artifact_set_count' => $sets->count(), 'issues' => $issues, 'pending' => $pending];
    }

    private function verifyIntake(PurchaseInvoiceIntake $intake): void
    {
        $events = AuditEvent::query()->where('legal_entity_id', $intake->legal_entity_id)
            ->where('target_type', $intake->getMorphClass())->where('target_id', (string) $intake->getKey())->get();
        $created = $events->where('operation', 'purchase_intake.created');
        $expected = ['disk' => $intake->disk, 'files' => $intake->files, 'identity' => $intake->identity];
        if ($created->count() !== 1 || $this->canonical->encode($created->first()?->payload) !== $this->canonical->encode($expected)
            || ! isset($intake->files['primary']) || $intake->disk === 'public') {
            throw new RuntimeException('Intake manifest does not match its creation evidence.');
        }
        $allPreserved = true;
        foreach ($intake->files as $role => $file) {
            $preserved = $intake->preserved_files[$role] ?? false;
            $recorded = $events->where('operation', 'purchase_intake.file_preserved')
                ->filter(fn (AuditEvent $event): bool => ($event->payload['role'] ?? null) === $role);
            if ($preserved !== ($recorded->count() === 1) || $recorded->count() > 1
                || $recorded->contains(fn (AuditEvent $event): bool => ($event->payload['sha256'] ?? null) !== $file['sha256'])) {
                throw new RuntimeException('Intake preservation state differs from its evidence.');
            }
            $allPreserved = $allPreserved && $preserved;
            $disk = Storage::disk($intake->disk);
            if ($preserved || $disk->exists($file['path'])) {
                $bytes = $disk->get($file['path']);
                if (! is_string($bytes) || strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['sha256']) {
                    throw new RuntimeException('Intake original is missing or changed.');
                }
            }
        }
        if (($intake->preserved_at !== null) !== $allPreserved || array_diff_key($intake->preserved_files, $intake->files) !== []) {
            throw new RuntimeException('Intake preservation state is inconsistent.');
        }
        $completed = $events->where('operation', 'purchase_intake.completed');
        if (($intake->document_id !== null) !== $completed->isNotEmpty()
            || ($intake->status === 'complete' && ($intake->document_id === null || ! $allPreserved))
            || $intake->status === 'integrity_failed'
            || $completed->contains(fn (AuditEvent $event): bool => ($event->payload['document_id'] ?? null) !== $intake->document_id)) {
            throw new RuntimeException('Intake completion differs from its evidence.');
        }
        if ($intake->document_id === null) {
            return;
        }
        $document = Document::query()->where('legal_entity_id', $intake->legal_entity_id)->findOrFail($intake->document_id);
        if ($document->type !== DocumentType::PurchaseInvoice || data_get($document->e_invoice_meta, 'intake_id') !== $intake->getKey()
            || data_get($document->e_invoice_meta, 'source_sha256') !== $intake->files['primary']['sha256']) {
            throw new RuntimeException('Intake invoice link differs from the original.');
        }
        foreach ($intake->files as $role => $file) {
            $attachment = $document->attachments()->where('source_type', $role === 'primary' ? 'original_invoice' : 'supplied_e_invoice')->sole();
            if (! $attachment instanceof Attachment || $attachment->disk !== $intake->disk || $attachment->path !== $file['path']
                || $attachment->sha256 !== $file['sha256'] || $attachment->size !== $file['size']) {
                throw new RuntimeException('Invoice attachment differs from its intake.');
            }
        }
        $this->originals->handle($document);
    }

    /** @return array<string, mixed> */
    private function item(string $code, string $message, string $type, int $id): array
    {
        return ['code' => $code, 'message' => $message, 'target_type' => $type, 'target_id' => $id];
    }
}
