<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Audit\AuditEvidenceExporter;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Services\CreateAuditAnchor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Trusted operator export service. Web callers must authorize and scope the entity first. */
final class AccountingDatasetExporter
{
    public function __construct(
        private readonly AuditEvidenceExporter $audit,
        private readonly JournalIntegrityVerifier $journals,
        private readonly InvoiceEvidenceVerifier $invoices,
        private readonly AuditLogger $logger,
        private readonly CanonicalJson $canonical,
        private readonly AccountingDatasetVerifier $verifier,
        private readonly CreateAuditAnchor $anchors,
    ) {}

    /** @return array<string, mixed> */
    public function build(LegalEntity $entity, bool $anchor = false): array
    {
        if ($entity->getConnection()->transactionLevel() !== 0) {
            throw new AuditEvidenceException('Dataset export requires an independent accounting transaction.');
        }

        $package = $entity->getConnection()->transaction(function () use ($entity): array {
            $entity = LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            // Refuse compromised inputs before recording a new export commitment.
            $this->audit->build($entity);
            $journal = $this->journals->verify((int) $entity->getKey());
            $invoice = $this->invoices->verify((int) $entity->getKey());
            if ($journal['issues'] !== [] || $invoice['issues'] !== []) {
                throw new AuditEvidenceException('Accounting dataset has integrity failures; run filament-accounting:verify.');
            }
            $records = [];
            foreach (AccountingDatasetSchema::COLUMNS as $table => $columns) {
                if (isset(AccountingDatasetSchema::CHILDREN[$table])) {
                    continue;
                }
                $query = $entity->getConnection()->table($table)->select(explode(' ', $columns));
                $query->where($table === 'accounting_legal_entities' ? 'id' : 'legal_entity_id', $entity->getKey());
                $records[$table] = $query->orderBy('id')->get()->map(fn (object $row): array => $this->record($row))->all();
            }
            foreach (AccountingDatasetSchema::CHILDREN as $table => [$column, $parent]) {
                $records[$table] = $entity->getConnection()->table($table)
                    ->select(explode(' ', AccountingDatasetSchema::COLUMNS[$table]))
                    ->whereIn($column, array_column($records[$parent], 'id'))->orderBy('id')->get()
                    ->map(fn (object $row): array => $this->record($row))->all();
            }
            $files = [];
            foreach ($records['accounting_attachments'] as $attachment) {
                $this->file($files, $attachment['disk'], $attachment['path'], $attachment['sha256'], (int) $attachment['size'], true);
            }
            foreach ($records['accounting_purchase_invoice_intakes'] as $intake) {
                $preserved = json_decode($intake['preserved_files'], true, 512, JSON_THROW_ON_ERROR);
                foreach (json_decode($intake['files'], true, 512, JSON_THROW_ON_ERROR) as $role => $file) {
                    $this->file($files, $intake['disk'], $file['path'], $file['sha256'], $file['size'], $preserved[$role] ?? false);
                }
            }
            foreach ($records['accounting_invoice_artifact_sets'] as $set) {
                $preserved = json_decode($set['preserved_roles'], true, 512, JSON_THROW_ON_ERROR);
                foreach (json_decode($set['manifest'], true, 512, JSON_THROW_ON_ERROR) as $role => $file) {
                    $this->file($files, $set['disk'], $file['path'], $file['sha256'], $file['size'], $preserved[$role] ?? false);
                }
            }
            ksort($files);
            $dataset = [
                'schema_version' => 1,
                'scope' => 'accounting-dataset-v1',
                'export_id' => (string) Str::uuid(),
                'legal_entity_id' => (string) $entity->getKey(),
                'legal_entity_uuid' => $entity->uuid,
                'exported_at' => now()->utc()->toIso8601String(),
                'schema' => ['columns' => AccountingDatasetSchema::COLUMNS, 'references' => AccountingDatasetSchema::references(),
                    'polymorphic_references' => AccountingDatasetSchema::POLYMORPHIC,
                    'scalar_encoding' => 'database strings or null; JSON columns retain their JSON text',
                    'file_encoding' => 'base64; never execute or extract paths from the package',
                    'excluded' => ['host tables', 'fints_institutes', 'fints_sca_sessions', 'bank connection credentials and protocol state', 'unreferenced storage objects', 'invoice logo/template assets']],
                'morph_types' => ['document' => (new Document)->getMorphClass(), 'journal' => (new JournalEntry)->getMorphClass(), 'reconciliation' => (new Reconciliation)->getMorphClass(), 'legal_entity' => (new LegalEntity)->getMorphClass()],
                'records' => $records,
                'files' => array_values($files),
                'pending' => $invoice['pending'],
            ];
            $hash = hash('sha256', $this->canonical->encode($dataset));
            $event = $this->logger->log($entity, 'accounting_export.prepared', $entity, [
                'export_id' => $dataset['export_id'], 'dataset_sha256' => $hash, 'scope' => $dataset['scope'],
            ]);
            $package = ['format' => 'filament-accounting-dataset', 'schema_version' => 1, 'dataset' => $dataset,
                'dataset_sha256' => $hash, 'export_event_sequence' => $event->sequence, 'audit_evidence' => $this->audit->build($entity)];
            $result = $this->verifier->verify($this->canonical->encode($package));
            if (! $result['valid']) {
                throw new AuditEvidenceException('Dataset failed portable verification: '.implode(', ', $result['issues']));
            }

            return $package;
        });
        // Anchor only committed evidence: storage cannot roll back with the DB.
        if ($anchor) {
            $this->anchors->handle($entity);
            $package['audit_evidence'] = $this->audit->build($entity);
        }

        return $package;
    }

    /** @return array<string, string|null> */
    private function record(object $row): array
    {
        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, (array) $row);
    }

    /** @param array<string, array<string, mixed>> $files */
    private function file(array &$files, string $disk, string $path, string $hash, int $size, bool $required): void
    {
        $key = hash('sha256', $disk."\0".$path);
        if (isset($files[$key])) {
            if ($files[$key]['sha256'] !== $hash || $files[$key]['size'] !== $size || ($required && ! $files[$key]['present'])) {
                throw new AuditEvidenceException('Conflicting retained file references.');
            }

            return;
        }
        $storage = Storage::disk($disk);
        $present = $storage->exists($path);
        $bytes = $present ? $storage->get($path) : null;
        if (($required && ! $present) || ($present && (! is_string($bytes) || strlen($bytes) !== $size || hash('sha256', $bytes) !== $hash))) {
            throw new AuditEvidenceException('A retained original is missing or changed.');
        }
        $files[$key] = ['disk' => $disk, 'path' => $path, 'sha256' => $hash, 'size' => $size, 'present' => $present,
            'contents_base64' => $bytes === null ? null : base64_encode($bytes)];
    }
}
