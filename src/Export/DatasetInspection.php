<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Exceptions\AuditEvidenceException;
use Generator;
use PDO;

/** Isolated, temporary on-disk inspection database. Never connects to the host DB. */
final class DatasetInspection
{
    public readonly PDO $database;

    public function __construct()
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new AuditEvidenceException('Streaming dataset inspection requires pdo_sqlite.');
        }
        $this->database = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA cache_size = -2048');
        $this->database->exec('PRAGMA temp_store = FILE');
        $this->database->exec('PRAGMA journal_mode = OFF');
        $this->database->exec('PRAGMA synchronous = OFF');
        foreach (AccountingDatasetSchema::COLUMNS as $table => $columns) {
            $fields = array_map(fn (string $name): string => '"'.$name.'" TEXT'.($name === 'id' ? ' PRIMARY KEY NOT NULL' : ''), explode(' ', $columns));
            $this->database->exec('CREATE TABLE "'.$table.'" ('.implode(',', $fields).')');
        }
        $this->database->exec('CREATE TABLE expected_files (key TEXT PRIMARY KEY, disk TEXT, path TEXT, sha256 TEXT, size INTEGER, required INTEGER)');
        $this->database->exec('CREATE TABLE stored_files (key TEXT PRIMARY KEY, disk TEXT, path TEXT, sha256 TEXT, size INTEGER, present INTEGER)');
        $this->database->exec('CREATE TABLE file_chunks (key TEXT, ordinal INTEGER, bytes BLOB, PRIMARY KEY(key, ordinal))');
        $this->database->exec('CREATE TABLE audit_events (sequence INTEGER PRIMARY KEY, data TEXT NOT NULL)');
        $this->database->exec('CREATE TABLE pending (data TEXT NOT NULL)');
    }

    /** @param array<string, mixed> $row */
    public function record(string $table, array $row, string $entityId, int $revision = AccountingDatasetSchema::REVISION): void
    {
        if (! isset(AccountingDatasetSchema::COLUMNS[$table])) {
            throw new AuditEvidenceException('Unknown dataset table.');
        }
        $columns = explode(' ', AccountingDatasetSchema::columns($revision)[$table]);
        if (count($columns) !== count($row) || array_diff($columns, array_keys($row)) !== []
            || ! is_string($row['id']) || ! ctype_digit($row['id'])) {
            throw new AuditEvidenceException('Malformed dataset record.');
        }
        foreach ($row as $value) {
            if (! is_string($value) && $value !== null) {
                throw new AuditEvidenceException('Dataset scalars must be strings or null.');
            }
        }
        if (isset($row['legal_entity_id']) && $row['legal_entity_id'] !== $entityId) {
            throw new AuditEvidenceException('Dataset contains another company.');
        }
        $this->insert($table, $columns, array_map(fn (string $column) => $row[$column], $columns));
        if ($table === 'accounting_attachments') {
            $this->expectFile($row['disk'], $row['path'], $row['sha256'], (int) $row['size'], true);
        }
        $manifest = ['accounting_purchase_invoice_intakes' => ['files', 'preserved_files'], 'accounting_invoice_artifact_sets' => ['manifest', 'preserved_roles']][$table] ?? null;
        if ($manifest !== null) {
            $files = json_decode($row[$manifest[0]], true, 512, JSON_THROW_ON_ERROR);
            $markers = json_decode($row[$manifest[1]], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($files) || ! is_array($markers)) {
                throw new AuditEvidenceException('Invalid file manifest.');
            }
            foreach ($files as $role => $file) {
                $this->expectFile($row['disk'], $file['path'], $file['sha256'], $file['size'], $markers[$role] ?? false);
            }
        }
    }

    /** @param list<string> $columns
     * @param  list<mixed>  $values
     */
    public function insert(string $table, array $columns, array $values): void
    {
        // All identifiers here are internal constants, never package-provided SQL.
        $sql = 'INSERT INTO "'.$table.'" ('.implode(',', array_map(fn (string $column): string => '"'.$column.'"', $columns)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')';
        $this->database->prepare($sql)->execute($values);
    }

    private function expectFile(string $disk, string $path, string $hash, int $size, bool $required): void
    {
        $key = hash('sha256', $disk."\0".$path);
        $statement = $this->database->prepare('SELECT * FROM expected_files WHERE key = ?');
        $statement->execute([$key]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        if ($stored !== false) {
            if ($stored['sha256'] !== $hash || (int) $stored['size'] !== $size) {
                throw new AuditEvidenceException('Conflicting file references.');
            }
            if ($required) {
                $this->database->prepare('UPDATE expected_files SET required = 1 WHERE key = ?')->execute([$key]);
            }
        } else {
            $this->insert('expected_files', ['key', 'disk', 'path', 'sha256', 'size', 'required'], [$key, $disk, $path, $hash, $size, (int) $required]);
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(string $table): Generator
    {
        $statement = $this->database->query('SELECT * FROM "'.$table.'"');
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    /** @param array<string, mixed> $header */
    public function validate(array $header, bool $files = true): void
    {
        $entities = $this->database->query('SELECT id, uuid FROM accounting_legal_entities')->fetchAll(PDO::FETCH_ASSOC);
        if (count($entities) !== 1 || $entities[0]['id'] !== $header['legal_entity_id'] || $entities[0]['uuid'] !== $header['legal_entity_uuid']) {
            throw new AuditEvidenceException('Dataset company identity mismatch.');
        }
        foreach (AccountingDatasetSchema::references($header['schema_revision'] ?? 1) as $source => $target) {
            [$table, $column] = explode('.', $source);
            $sql = 'SELECT 1 FROM "'.$table.'" a LEFT JOIN "'.$target.'" b ON a."'.$column.'" = b.id WHERE a."'.$column.'" IS NOT NULL AND b.id IS NULL LIMIT 1';
            if ($this->database->query($sql)->fetchColumn() !== false) {
                throw new AuditEvidenceException('Dataset reference missing: '.$source);
            }
        }
        foreach (AccountingDatasetSchema::POLYMORPHIC as $table => [$type, $id, $targets]) {
            foreach ($targets as $value => $target) {
                $sql = 'SELECT 1 FROM "'.$table.'" a LEFT JOIN "'.$target.'" b ON a."'.$id.'" = b.id WHERE a."'.$type.'" = ? AND b.id IS NULL LIMIT 1';
                $query = $this->database->prepare($sql);
                $query->execute([$value]);
                if ($query->fetchColumn() !== false) {
                    throw new AuditEvidenceException('Polymorphic dataset reference missing.');
                }
            }
        }
        $targets = ['document' => 'accounting_documents', 'journal' => 'accounting_journal_entries', 'reconciliation' => 'accounting_reconciliations', 'legal_entity' => 'accounting_legal_entities'];
        $morph = $header['morph_types'];
        foreach ($this->rows('accounting_attachments') as $attachment) {
            $key = array_search($attachment['attachable_type'], $morph, true);
            if ($key === false || ! isset($targets[$key])) {
                throw new AuditEvidenceException('Unsupported attachment owner.');
            }
            $query = $this->database->prepare('SELECT 1 FROM "'.$targets[$key].'" WHERE id = ?');
            $query->execute([$attachment['attachable_id']]);
            if ($query->fetchColumn() === false) {
                throw new AuditEvidenceException('Missing attachment owner.');
            }
        }
        if ($files) {
            $bad = $this->database->query('SELECT 1 FROM expected_files e LEFT JOIN stored_files s ON e.key = s.key WHERE s.key IS NULL OR e.sha256 != s.sha256 OR e.size != s.size OR (e.required = 1 AND s.present = 0) LIMIT 1')->fetchColumn();
            $extra = $this->database->query('SELECT 1 FROM stored_files s LEFT JOIN expected_files e ON e.key = s.key WHERE e.key IS NULL LIMIT 1')->fetchColumn();
            if ($bad !== false || $extra !== false) {
                throw new AuditEvidenceException('Dataset file inventory mismatch.');
            }
        }
    }
}
