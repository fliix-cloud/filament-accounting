<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Audit\AuditAnchorVerifier;
use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Audit\InvoiceEvidenceVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Services\CreateAuditAnchor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Trusted console service; the returned temporary stream belongs to the caller. */
final class StreamDatasetExporter
{
    public function __construct(
        private readonly CanonicalJson $json,
        private readonly AuditChainVerifier $chain,
        private readonly AuditAnchorVerifier $anchorVerifier,
        private readonly AuditAnchorStore $anchorStore,
        private readonly JournalIntegrityVerifier $journals,
        private readonly InvoiceEvidenceVerifier $invoices,
        private readonly AuditLogger $logger,
        private readonly CreateAuditAnchor $anchor,
        private readonly StreamDatasetVerifier $verifier,
    ) {}

    /** @return array{stream: resource, report: array<string, mixed>} */
    public function build(LegalEntity $entity, bool $anchor = false): array
    {
        if ($entity->getConnection()->transactionLevel() !== 0) {
            throw new AuditEvidenceException('Dataset export requires an independent accounting transaction.');
        }
        $stream = tmpfile();
        if ($stream === false) {
            throw new AuditEvidenceException('Cannot allocate temporary export storage.');
        }
        try {
            $index = new DatasetInspection;
            $hash = hash_init('sha256');
            $this->write($stream, StreamDatasetVerifier::MAGIC);
            hash_update($hash, StreamDatasetVerifier::MAGIC);
            $body = function (array $frame) use ($stream, $hash): void {
                $line = $this->json->encode($frame)."\n";
                $this->write($stream, $line);
                hash_update($hash, $line);
            };
            $counts = ['records' => 0, 'files' => 0, 'pending' => 0];
            $entity->getConnection()->transaction(function () use ($entity, $anchor, $index, $stream, $hash, $body, &$counts): void {
                $entity = LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $chain = $this->chain->verify((int) $entity->getKey());
                if (! $chain->isValid() || ! $this->anchorVerifier->verify($entity, $chain)->isValid()) {
                    throw new AuditEvidenceException('Audit integrity failed before export.');
                }
                $this->journals->assertValid((int) $entity->getKey());
                $header = ['kind' => 'header', 'schema_version' => 2, 'schema_revision' => AccountingDatasetSchema::REVISION, 'scope' => 'accounting-dataset-v2', 'export_id' => (string) Str::uuid(),
                    'exported_at' => now()->utc()->toIso8601String(), 'legal_entity_id' => (string) $entity->getKey(), 'legal_entity_uuid' => $entity->uuid,
                    'columns' => AccountingDatasetSchema::COLUMNS, 'references' => AccountingDatasetSchema::references(), 'polymorphic_references' => AccountingDatasetSchema::POLYMORPHIC,
                    'morph_types' => ['document' => (new Document)->getMorphClass(), 'journal' => (new JournalEntry)->getMorphClass(), 'reconciliation' => (new Reconciliation)->getMorphClass(), 'legal_entity' => (new LegalEntity)->getMorphClass()],
                    'anchor_requested' => $anchor, 'anchor_policy' => ['required' => (bool) config('filament-accounting.audit.anchor.required', false),
                        'immutable_storage_attested' => (bool) config('filament-accounting.audit.anchor.immutable_storage_attested', false)]];
                $body($header);
                $invoice = $this->invoices->verify((int) $entity->getKey(), function (array $pending) use ($body, &$counts): void {
                    $body(['kind' => 'pending', 'data' => $pending]);
                    $counts['pending']++;
                });
                if ($invoice['issues'] !== []) {
                    throw new AuditEvidenceException('Invoice integrity failed before export.');
                }
                foreach (AccountingDatasetSchema::COLUMNS as $table => $columns) {
                    $body(['kind' => 'table', 'name' => $table]);
                    $query = $entity->getConnection()->table($table)->select(explode(' ', $columns));
                    if (isset(AccountingDatasetSchema::CHILDREN[$table])) {
                        [$column, $parent] = AccountingDatasetSchema::CHILDREN[$table];
                        $query->whereIn($column, $entity->getConnection()->table($parent)->select('id')->where('legal_entity_id', $entity->getKey()));
                    } else {
                        $query->where($table === 'accounting_legal_entities' ? 'id' : 'legal_entity_id', $entity->getKey());
                    }
                    // A staged invoice can itself be large; retain only one row.
                    foreach ($query->lazyById(1) as $row) {
                        $record = array_map(fn ($value): ?string => $value === null ? null : (string) $value, (array) $row);
                        $index->record($table, $record, (string) $entity->getKey());
                        $body(['kind' => 'record', 'data' => $record]);
                        $counts['records']++;
                    }
                }
                $index->validate($header, false);
                foreach ($index->rows('expected_files') as $file) {
                    $disk = Storage::disk($file['disk']);
                    $present = $disk->exists($file['path']);
                    if (! $present && $file['required']) {
                        throw new AuditEvidenceException('A preserved file is missing.');
                    }
                    $body(['kind' => 'file', 'data' => ['disk' => $file['disk'], 'path' => $file['path'], 'sha256' => $file['sha256'], 'size' => (int) $file['size'], 'present' => $present]]);
                    if ($present) {
                        $source = $disk->readStream($file['path']);
                        if (! is_resource($source)) {
                            throw new AuditEvidenceException('Cannot read retained file stream.');
                        }
                        $fileHash = hash_init('sha256');
                        $size = 0;
                        try {
                            while (! feof($source)) {
                                $bytes = fread($source, 65536);
                                if ($bytes === false || ($bytes === '' && ! feof($source))) {
                                    throw new AuditEvidenceException('Retained file read failed.');
                                }
                                if ($bytes === '') {
                                    break;
                                }
                                $size += strlen($bytes);
                                if ($size > (int) $file['size']) {
                                    throw new AuditEvidenceException('Retained file exceeds its manifest size.');
                                }
                                hash_update($fileHash, $bytes);
                                $body(['kind' => 'chunk', 'base64' => base64_encode($bytes)]);
                            }
                        } finally {
                            fclose($source);
                        }
                        if ($size !== (int) $file['size'] || hash_final($fileHash) !== $file['sha256']) {
                            throw new AuditEvidenceException('Retained file changed during export.');
                        }
                    }
                    $body(['kind' => 'file_end']);
                    $counts['files']++;
                }
                $digest = hash_final($hash);
                $event = $this->logger->log($entity, 'accounting_export.prepared', $entity,
                    ['dataset_sha256' => $digest, 'export_id' => $header['export_id'], 'scope' => $header['scope']]);
                $this->write($stream, $this->json->encode(['kind' => 'dataset_end', 'sha256' => $digest, 'export_event_sequence' => $event->sequence])."\n");
            });
            // Never anchor an event which could still be rolled back by the exporter.
            if ($anchor) {
                $this->anchor->handle($entity);
            }
            $entity->getConnection()->transaction(function () use ($entity, $stream, $counts): void {
                LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $head = (array) $entity->getConnection()->table('accounting_audit_chain_heads')->where('legal_entity_id', $entity->getKey())->first();
                foreach (AuditEvent::query()->where('legal_entity_id', $entity->getKey())->where('sequence', '<=', $head['last_sequence'])->orderBy('sequence')->lazy(1) as $event) {
                    $data = $event->getAttributes();
                    unset($data['id']);
                    $this->write($stream, $this->json->encode(['kind' => 'event', 'data' => $data])."\n");
                }
                $anchors = array_map(fn ($item): array => $item->toArray(), $this->anchorStore->all((string) $entity->uuid));
                $this->write($stream, $this->json->encode(['kind' => 'footer', 'head' => $head, 'anchors' => $anchors, 'counts' => $counts])."\n");
            });
            rewind($stream);
            $report = $this->verifier->verify($stream);
            rewind($stream);

            return ['stream' => $stream, 'report' => $report];
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    /** @param resource $stream */
    private function write($stream, string $bytes): void
    {
        if (strlen($bytes) > StreamDatasetVerifier::MAX_LINE_BYTES) {
            throw new AuditEvidenceException('A dataset frame exceeds the 64 MiB safety limit.');
        }
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new AuditEvidenceException('Temporary export storage write failed.');
            }
            $offset += $written;
        }
    }
}
