<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Audit\AuditEvidenceVerifier;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Exceptions\AuditEvidenceException;

/** Portable verification: no model queries, storage reads, or writes. */
final class AccountingDatasetVerifier
{
    public function __construct(private readonly CanonicalJson $canonical, private readonly AuditEvidenceVerifier $audit) {}

    /** @return array{schema_version: int, valid: bool, issues: list<string>, dataset_sha256: string, export_event_anchored: bool, table_count: int, file_count: int} */
    public function verify(string $contents): array
    {
        $package = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($package) || ($package['format'] ?? null) !== 'filament-accounting-dataset' || ($package['schema_version'] ?? null) !== 1
            || ! is_array($package['dataset'] ?? null) || ! is_array($package['audit_evidence'] ?? null)) {
            throw new AuditEvidenceException('Unsupported accounting dataset package.');
        }
        $dataset = $package['dataset'];
        $issues = [];
        $hash = hash('sha256', $this->canonical->encode($dataset));
        if (($package['dataset_sha256'] ?? null) !== $hash) {
            $issues[] = 'dataset_hash_mismatch';
        }
        if (($dataset['schema_version'] ?? null) !== 1 || ($dataset['scope'] ?? null) !== 'accounting-dataset-v1'
            || $this->canonical->encode($dataset['schema']['columns'] ?? null) !== $this->canonical->encode(AccountingDatasetSchema::COLUMNS)
            || $this->canonical->encode($dataset['schema']['references'] ?? null) !== $this->canonical->encode(AccountingDatasetSchema::references())
            || $this->canonical->encode($dataset['schema']['polymorphic_references'] ?? null) !== $this->canonical->encode(AccountingDatasetSchema::POLYMORPHIC)) {
            throw new AuditEvidenceException('Unsupported dataset schema or relationship description.');
        }
        $audit = $this->audit->verify($this->canonical->encode($package['audit_evidence']));
        if (! $audit->isValid()) {
            $issues[] = 'dataset_audit_evidence_invalid';
        }
        $events = $package['audit_evidence']['audit_chain']['events'];
        $commitments = array_values(array_filter($events, fn (array $event): bool => (int) $event['sequence'] === ($package['export_event_sequence'] ?? null)));
        $event = $commitments[0] ?? [];
        $payload = isset($event['payload']) && is_string($event['payload']) ? json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR) : ($event['payload'] ?? []);
        if (count($commitments) !== 1 || ($event['operation'] ?? null) !== 'accounting_export.prepared'
            || ($payload['dataset_sha256'] ?? null) !== $hash || ($payload['export_id'] ?? null) !== ($dataset['export_id'] ?? null)
            || ($payload['scope'] ?? null) !== $dataset['scope']
            || (string) ($package['audit_evidence']['legal_entity']['id'] ?? '') !== ($dataset['legal_entity_id'] ?? null)
            || ($package['audit_evidence']['legal_entity']['uuid'] ?? null) !== ($dataset['legal_entity_uuid'] ?? null)) {
            $issues[] = 'dataset_commitment_mismatch';
        }
        $records = $this->records($dataset);
        $entity = $records['accounting_legal_entities'][$dataset['legal_entity_id']] ?? null;
        if (count($records['accounting_legal_entities']) !== 1 || ($entity['uuid'] ?? null) !== $dataset['legal_entity_uuid']) {
            $issues[] = 'dataset_entity_mismatch';
        }
        foreach (AccountingDatasetSchema::references() as $reference => $target) {
            [$table, $column] = explode('.', $reference);
            foreach ($records[$table] as $row) {
                if ($row[$column] !== null && ! isset($records[$target][$row[$column]])) {
                    $issues[] = 'dataset_reference_missing:'.$reference.':'.$row['id'];
                }
            }
        }
        $morph = $dataset['morph_types'] ?? [];
        $morphTables = [];
        foreach (['document' => 'accounting_documents', 'journal' => 'accounting_journal_entries', 'reconciliation' => 'accounting_reconciliations', 'legal_entity' => 'accounting_legal_entities'] as $key => $table) {
            if (! is_string($morph[$key] ?? null) || $morph[$key] === '' || isset($morphTables[$morph[$key]])) {
                throw new AuditEvidenceException('Invalid morph type map.');
            }
            $morphTables[$morph[$key]] = $table;
        }
        foreach ($records['accounting_attachments'] as $row) {
            $target = $morphTables[$row['attachable_type']] ?? null;
            if ($target === null || ! isset($records[$target][$row['attachable_id']])) {
                $issues[] = 'dataset_attachment_target_missing:'.$row['id'];
            }
        }
        foreach (AccountingDatasetSchema::POLYMORPHIC as $table => [$typeColumn, $idColumn, $targets]) {
            foreach ($records[$table] as $row) {
                $target = $targets[$row[$typeColumn]] ?? null;
                if ($target !== null && ! isset($records[$target][$row[$idColumn]])) {
                    $issues[] = 'dataset_polymorphic_reference_missing:'.$table.':'.$row['id'];
                }
            }
        }
        $this->files($dataset, $records, $issues);

        return ['schema_version' => 1, 'valid' => $issues === [], 'issues' => $issues, 'dataset_sha256' => $hash,
            'export_event_anchored' => $audit->isValid() && ($audit->anchors->lastAnchoredSequence ?? 0) >= ($package['export_event_sequence'] ?? PHP_INT_MAX),
            'table_count' => count($records), 'file_count' => count($dataset['files'])];
    }

    /** @param array<string, mixed> $dataset
     * @return array<string, array<int|string, array<string, string|null>>>
     */
    private function records(array $dataset): array
    {
        $input = $dataset['records'] ?? null;
        if (! is_array($input) || count($input) !== count(AccountingDatasetSchema::COLUMNS)) {
            throw new AuditEvidenceException('Dataset table inventory is incomplete.');
        }
        $records = [];
        foreach (AccountingDatasetSchema::COLUMNS as $table => $columns) {
            if (! isset($input[$table]) || ! is_array($input[$table]) || ! array_is_list($input[$table])) {
                throw new AuditEvidenceException('Missing or malformed dataset table: '.$table);
            }
            $records[$table] = [];
            $expected = explode(' ', $columns);
            foreach ($input[$table] as $row) {
                if (! is_array($row) || count($row) !== count($expected) || array_diff($expected, array_keys($row)) !== []
                    || ! is_string($row['id']) || ! ctype_digit($row['id']) || isset($records[$table][$row['id']])) {
                    throw new AuditEvidenceException('Malformed or duplicate dataset record: '.$table);
                }
                foreach ($row as $value) {
                    if ($value !== null && ! is_string($value)) {
                        throw new AuditEvidenceException('Dataset scalars must be strings or null.');
                    }
                }
                if (array_key_exists('legal_entity_id', $row) && $row['legal_entity_id'] !== ($dataset['legal_entity_id'] ?? null)) {
                    throw new AuditEvidenceException('Dataset contains a record belonging to another entity.');
                }
                $records[$table][$row['id']] = $row;
            }
        }

        return $records;
    }

    /** @param array<string, mixed> $dataset
     * @param  array<string, array<int|string, array<string, string|null>>>  $records
     * @param  list<string>  $issues
     */
    private function files(array $dataset, array $records, array &$issues): void
    {
        if (! is_array($dataset['files'] ?? null) || ! array_is_list($dataset['files'])) {
            throw new AuditEvidenceException('Dataset file inventory is malformed.');
        }
        $files = [];
        foreach ($dataset['files'] as $file) {
            if (! is_array($file) || ! is_string($file['disk'] ?? null) || ! is_string($file['path'] ?? null)
                || ! is_int($file['size'] ?? null) || ! is_string($file['sha256'] ?? null) || ! is_bool($file['present'] ?? null)) {
                throw new AuditEvidenceException('Malformed dataset file.');
            }
            $key = hash('sha256', $file['disk']."\0".$file['path']);
            if (isset($files[$key])) {
                throw new AuditEvidenceException('Duplicate dataset file.');
            }
            if ($file['present']) {
                $bytes = is_string($file['contents_base64'] ?? null) ? base64_decode($file['contents_base64'], true) : false;
                if (! is_string($bytes) || strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['sha256']) {
                    $issues[] = 'dataset_file_hash_mismatch:'.$key;
                }
            } elseif (($file['contents_base64'] ?? null) !== null) {
                $issues[] = 'dataset_absent_file_has_contents:'.$key;
            }
            $files[$key] = $file;
        }
        $used = [];
        $check = function (string $disk, string $path, string $hash, int $size, bool $required) use ($files, &$used, &$issues): void {
            $key = hash('sha256', $disk."\0".$path);
            $file = $files[$key] ?? null;
            if ($file === null || $file['sha256'] !== $hash || $file['size'] !== $size || ($required && ! $file['present'])) {
                $issues[] = 'dataset_file_reference_invalid:'.$key;
            }
            $used[$key] = true;
        };
        foreach ($records['accounting_attachments'] as $row) {
            $check($row['disk'], $row['path'], $row['sha256'], (int) $row['size'], true);
        }
        foreach (['accounting_purchase_invoice_intakes' => ['files', 'preserved_files'], 'accounting_invoice_artifact_sets' => ['manifest', 'preserved_roles']] as $table => [$manifest, $markers]) {
            foreach ($records[$table] as $row) {
                $preserved = json_decode($row[$markers], true, 512, JSON_THROW_ON_ERROR);
                foreach (json_decode($row[$manifest], true, 512, JSON_THROW_ON_ERROR) as $role => $file) {
                    $check($row['disk'], $file['path'], $file['sha256'], $file['size'], $preserved[$role] ?? false);
                }
            }
        }
        if (array_diff_key($files, $used) !== []) {
            $issues[] = 'dataset_unreferenced_file';
        }
    }
}
