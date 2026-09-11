<?php

namespace FilamentAccounting\Export;

use Closure;
use FilamentAccounting\Audit\AuditAnchor;
use FilamentAccounting\Audit\AuditAnchorChainValidator;
use FilamentAccounting\Audit\AuditEventChainValidator;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use PDO;

final class StreamDatasetVerifier
{
    public const MAGIC = "FILAMENT-ACCOUNTING-DATASET-2\n";

    public const MAX_LINE_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly CanonicalJson $json, private readonly AuditEventChainValidator $chain, private readonly AuditAnchorChainValidator $anchors) {}

    /**
     * @param  resource  $stream  Positioned before the magic line, including on non-seekable disks.
     * @param  Closure(DatasetInspection): void|null  $inspect  Called only after successful verification.
     * @return array<string, mixed>
     */
    public function verify($stream, ?Closure $inspect = null, bool $magicConsumed = false): array
    {
        if (! is_resource($stream) || (! $magicConsumed && fgets($stream, 128) !== self::MAGIC)) {
            throw new AuditEvidenceException('Unsupported streaming dataset format.');
        }
        $index = new DatasetInspection;
        $hash = hash_init('sha256');
        hash_update($hash, self::MAGIC);
        $transportHash = hash_init('sha256');
        hash_update($transportHash, self::MAGIC);
        $header = null;
        $tables = [];
        $activeTable = null;
        $activeFile = null;
        $fileHash = null;
        $fileBytes = 0;
        $chunk = 0;
        $counts = ['records' => 0, 'files' => 0, 'pending' => 0];
        $end = null;
        $footer = null;
        $filePhase = false;
        $nextEvent = 1;
        while (($line = $this->line($stream)) !== false) {
            hash_update($transportHash, $line);
            if (! str_ends_with($line, "\n") || strlen($line) > self::MAX_LINE_BYTES || $footer !== null) {
                throw new AuditEvidenceException('Oversized, truncated, or trailing dataset input.');
            }
            $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($item) || ! is_string($item['kind'] ?? null)) {
                throw new AuditEvidenceException('Malformed dataset frame.');
            }
            $kind = $item['kind'];
            if ($end === null && $kind !== 'dataset_end') {
                hash_update($hash, $line);
            }
            if ($header === null && $kind !== 'header') {
                throw new AuditEvidenceException('Dataset header missing.');
            }
            if ($end !== null && ! in_array($kind, ['event', 'footer'], true)) {
                throw new AuditEvidenceException('Unexpected frame after dataset end.');
            }
            if ($activeFile !== null && ! in_array($kind, ['chunk', 'file_end'], true)) {
                throw new AuditEvidenceException('Interrupted file frame.');
            }
            switch ($kind) {
                case 'header':
                    if ($header !== null || ($item['schema_version'] ?? null) !== 2
                        || ($item['scope'] ?? null) !== 'accounting-dataset-v2'
                        || ! is_string($item['legal_entity_id'] ?? null) || ! ctype_digit($item['legal_entity_id'])
                        || ! is_string($item['legal_entity_uuid'] ?? null) || ! is_string($item['export_id'] ?? null)
                        || ! is_bool($item['anchor_requested'] ?? null) || ! is_bool($item['anchor_policy']['required'] ?? null)
                        || ! is_bool($item['anchor_policy']['immutable_storage_attested'] ?? null)
                        || $this->json->encode($item['columns'] ?? null) !== $this->json->encode(AccountingDatasetSchema::columns($item['schema_revision'] ?? 1))
                        || $this->json->encode($item['references'] ?? null) !== $this->json->encode(AccountingDatasetSchema::references($item['schema_revision'] ?? 1))
                        || $this->json->encode($item['polymorphic_references'] ?? null) !== $this->json->encode(AccountingDatasetSchema::POLYMORPHIC)
                        || ! is_array($item['morph_types'] ?? null)) {
                        throw new AuditEvidenceException('Invalid streaming dataset header.');
                    }
                    $morphValues = [];
                    foreach (['document', 'journal', 'reconciliation', 'legal_entity'] as $key) {
                        $value = $item['morph_types'][$key] ?? null;
                        if (! is_string($value) || $value === '' || in_array($value, $morphValues, true)) {
                            throw new AuditEvidenceException('Invalid attachment type map.');
                        }
                        $morphValues[] = $value;
                    }
                    $header = $item;
                    break;
                case 'table':
                    $table = $item['name'] ?? '';
                    if ($filePhase || ! is_string($table) || ! isset(AccountingDatasetSchema::COLUMNS[$table]) || isset($tables[$table])) {
                        throw new AuditEvidenceException('Unexpected or duplicate dataset table.');
                    }
                    $tables[$table] = true;
                    $activeTable = $table;
                    break;
                case 'record':
                    if ($filePhase || $activeTable === null || ! is_array($item['data'] ?? null)) {
                        throw new AuditEvidenceException('Unexpected dataset record.');
                    }
                    $index->record($activeTable, $item['data'], $header['legal_entity_id'], $header['schema_revision'] ?? 1);
                    $counts['records']++;
                    break;
                case 'pending':
                    if (! is_array($item['data'] ?? null)) {
                        throw new AuditEvidenceException('Invalid pending item.');
                    }
                    $index->insert('pending', ['data'], [$this->json->encode($item['data'])]);
                    $counts['pending']++;
                    break;
                case 'file':
                    $filePhase = true;
                    $file = $item['data'] ?? null;
                    if (count($tables) !== count(AccountingDatasetSchema::COLUMNS) || ! is_array($file)
                        || ! is_string($file['disk'] ?? null) || ! is_string($file['path'] ?? null)
                        || ! is_string($file['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $file['sha256'])
                        || ! is_int($file['size'] ?? null) || $file['size'] < 0 || ! is_bool($file['present'] ?? null)) {
                        throw new AuditEvidenceException('Invalid file frame.');
                    }
                    $activeFile = array_merge($file, ['key' => hash('sha256', $file['disk']."\0".$file['path'])]);
                    $fileHash = hash_init('sha256');
                    $fileBytes = 0;
                    $chunk = 0;
                    break;
                case 'chunk':
                    if ($activeFile === null || ! $activeFile['present'] || ! is_string($item['base64'] ?? null)) {
                        throw new AuditEvidenceException('Unexpected file chunk.');
                    }
                    $bytes = base64_decode($item['base64'], true);
                    if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 65536) {
                        throw new AuditEvidenceException('Invalid file chunk.');
                    }
                    $fileBytes += strlen($bytes);
                    if ($fileBytes > $activeFile['size']) {
                        throw new AuditEvidenceException('File exceeds declared size.');
                    }
                    hash_update($fileHash, $bytes);
                    $statement = $index->database->prepare('INSERT INTO file_chunks VALUES (?, ?, ?)');
                    $statement->bindValue(1, $activeFile['key']);
                    $statement->bindValue(2, $chunk++);
                    $statement->bindValue(3, $bytes, PDO::PARAM_LOB);
                    $statement->execute();
                    break;
                case 'file_end':
                    if ($activeFile === null || ($activeFile['present'] && ($fileBytes !== $activeFile['size'] || hash_final($fileHash) !== $activeFile['sha256']))) {
                        throw new AuditEvidenceException('File is truncated or changed.');
                    }
                    $index->insert('stored_files', ['key', 'disk', 'path', 'sha256', 'size', 'present'], [
                        $activeFile['key'], $activeFile['disk'], $activeFile['path'], $activeFile['sha256'], $activeFile['size'], (int) $activeFile['present']]);
                    $activeFile = null;
                    $counts['files']++;
                    break;
                case 'dataset_end':
                    if (count($tables) !== count(AccountingDatasetSchema::COLUMNS) || ($item['sha256'] ?? null) !== hash_final($hash)
                        || ! is_int($item['export_event_sequence'] ?? null) || $item['export_event_sequence'] < 1) {
                        throw new AuditEvidenceException('Dataset inventory or digest mismatch.');
                    }
                    $end = $item;
                    break;
                case 'event':
                    if ($end === null || ! is_array($item['data'] ?? null) || (int) ($item['data']['sequence'] ?? 0) !== $nextEvent++) {
                        throw new AuditEvidenceException('Unexpected audit event.');
                    }
                    $index->insert('audit_events', ['sequence', 'data'], [$item['data']['sequence'], $this->json->encode($item['data'])]);
                    break;
                case 'footer':
                    if ($end === null || $this->json->encode($item['counts'] ?? null) !== $this->json->encode($counts) || ! is_array($item['head'] ?? null) || ! is_array($item['anchors'] ?? null)) {
                        throw new AuditEvidenceException('Dataset footer mismatch.');
                    }
                    $footer = $item;
                    break;
                default:
                    throw new AuditEvidenceException('Unknown dataset frame.');
            }
        }
        if (! feof($stream) || $footer === null || $end === null) {
            throw new AuditEvidenceException('Dataset is incomplete.');
        }
        $index->validate($header);
        $events = (function () use ($index) {
            $query = $index->database->query('SELECT data FROM audit_events ORDER BY sequence');
            while (($data = $query->fetchColumn()) !== false) {
                yield json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            }
        })();
        $chain = $this->chain->verify((int) $header['legal_entity_id'], $events, $footer['head'], true);
        if (! $chain->isValid()) {
            throw new AuditEvidenceException('Dataset audit chain invalid.');
        }
        $query = $index->database->prepare('SELECT data FROM audit_events WHERE sequence = ?');
        $query->execute([$end['export_event_sequence']]);
        $event = json_decode($query->fetchColumn() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $payload = is_string($event['payload'] ?? null) ? json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR) : ($event['payload'] ?? []);
        if (($event['operation'] ?? null) !== 'accounting_export.prepared' || ($payload['dataset_sha256'] ?? null) !== $end['sha256']
            || ($payload['export_id'] ?? null) !== $header['export_id'] || ($payload['scope'] ?? null) !== $header['scope']) {
            throw new AuditEvidenceException('Dataset commitment mismatch.');
        }
        $anchors = array_map(fn (array $data): AuditAnchor => AuditAnchor::fromArray($data), $footer['anchors']);
        $hashes = [];
        foreach ($anchors as $anchor) {
            $query->execute([$anchor->lastSequence]);
            $event = json_decode($query->fetchColumn() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            $hashes[$anchor->lastSequence] = $event['event_hash'] ?? '';
        }
        $result = $this->anchors->verify((int) $header['legal_entity_id'], $header['legal_entity_uuid'], $chain, $anchors, $hashes,
            $header['anchor_policy']['required'], $header['anchor_policy']['immutable_storage_attested']);
        $anchored = ($result->lastAnchoredSequence ?? 0) >= $end['export_event_sequence'];
        if (! $result->isValid() || ($header['anchor_requested'] && ! $anchored)) {
            throw new AuditEvidenceException('Dataset external anchor invalid or missing.');
        }
        if ($inspect !== null) {
            $inspect($index);
        }

        return ['schema_version' => 2, 'schema_revision' => $header['schema_revision'] ?? 1, 'valid' => true, 'issues' => [], 'dataset_sha256' => $end['sha256'], 'export_event_anchored' => $anchored,
            'package_sha256' => hash_final($transportHash),
            'table_count' => count($tables), 'file_count' => $counts['files'], 'record_count' => $counts['records'], 'pending_count' => $counts['pending']];
    }

    /** @param resource $stream */
    private function line($stream): string|false
    {
        $line = '';
        while (($part = fgets($stream, 65537)) !== false) {
            $line .= $part;
            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw new AuditEvidenceException('Dataset frame exceeds the size limit.');
            }
            if (str_ends_with($part, "\n")) {
                return $line;
            }
        }

        return $line === '' ? false : $line;
    }
}
