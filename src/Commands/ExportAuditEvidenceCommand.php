<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditEvidenceExporter;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Export\AccountingDatasetExporter;
use FilamentAccounting\Export\AccountingDatasetVerifier;
use FilamentAccounting\Export\StreamDatasetExporter;
use FilamentAccounting\Export\StreamDatasetVerifier;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

class ExportAuditEvidenceCommand extends Command
{
    protected $signature = 'filament-accounting:audit-export
        {legal-entity : Legal-entity ID or UUID}
        {path : Relative output path on the selected filesystem disk}
        {--disk= : Laravel filesystem disk; defaults to the package storage disk}
        {--dataset : Include the linked accounting dataset and retained file contents}
        {--legacy-json : With --dataset, write the old in-memory JSON v1 format}
        {--anchor : With --dataset, anchor the committed export before writing the package}
        {--json : Emit a machine-readable command report}';

    protected $description = 'Export a portable, offline-verifiable audit-evidence document';

    public function handle(
        AuditEvidenceExporter $exporter,
        FilesystemFactory $filesystems,
        AccountingDatasetExporter $datasets,
        AccountingDatasetVerifier $datasetVerifier,
        StreamDatasetExporter $streamDatasets,
        StreamDatasetVerifier $streamVerifier,
    ): int {
        try {
            $selector = (string) $this->argument('legal-entity');
            if ($this->option('anchor') && ! $this->option('dataset')) {
                throw new AuditEvidenceException('--anchor requires --dataset.');
            }
            if ($this->option('legacy-json') && ! $this->option('dataset')) {
                throw new AuditEvidenceException('--legacy-json requires --dataset.');
            }
            $path = $this->path((string) $this->argument('path'));
            $diskName = (string) ($this->option('disk') ?: config('filament-accounting.storage.disk', 'local'));
            if ($diskName === 'public') {
                throw new AuditEvidenceException('Accounting exports require private storage.');
            }
            $entity = LegalEntity::query()
                ->where(function ($query) use ($selector): void {
                    $query->where('uuid', $selector);

                    if (ctype_digit($selector)) {
                        $query->orWhereKey((int) $selector);
                    }
                })
                ->first();

            if (! $entity instanceof LegalEntity) {
                throw new AuditEvidenceException('Legal entity was not found.');
            }

            $disk = $filesystems->disk($diskName);

            if ($disk->exists($path)) {
                throw new AuditEvidenceException("Refusing to overwrite existing audit-evidence file [{$path}].");
            }

            if ($this->option('dataset') && ! $this->option('legacy-json')) {
                $built = $streamDatasets->build($entity, (bool) $this->option('anchor'));
                try {
                    if (! $disk->put($path, $built['stream'], ['visibility' => 'private'])) {
                        throw new AuditEvidenceException('Streaming dataset could not be written.');
                    }
                    $readback = $disk->readStream($path);
                    if (! is_resource($readback)) {
                        throw new AuditEvidenceException('Streaming dataset read-back failed.');
                    }
                    try {
                        $inspection = $streamVerifier->verify($readback);
                    } finally {
                        fclose($readback);
                    }
                    if ($inspection['package_sha256'] !== $built['report']['package_sha256']) {
                        throw new AuditEvidenceException('Dataset failed read-after-write verification.');
                    }
                    $report = ['schema_version' => 1, 'valid' => true, 'disk' => $diskName, 'path' => $path,
                        'legal_entity_id' => (int) $entity->getKey(), 'legal_entity_uuid' => $entity->uuid,
                        'evidence_hash' => $inspection['dataset_sha256'], 'dataset' => $inspection];
                } finally {
                    fclose($built['stream']);
                }
            } else {
                $evidence = $this->option('dataset') ? $datasets->build($entity, (bool) $this->option('anchor')) : $exporter->build($entity);
                $contents = $exporter->encode($evidence);

                if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
                    throw new AuditEvidenceException("Audit-evidence file [{$path}] could not be written.");
                }

                if (! hash_equals($contents, $disk->get($path))) {
                    throw new AuditEvidenceException("Audit-evidence file [{$path}] failed read-after-write verification.");
                }

                $report = [
                    'schema_version' => 1,
                    'valid' => true,
                    'disk' => $diskName,
                    'path' => $path,
                    'legal_entity_id' => (int) $entity->getKey(),
                    'legal_entity_uuid' => (string) $entity->uuid,
                    'evidence_hash' => $evidence['evidence_hash'] ?? $evidence['dataset_sha256'],
                ];
                if ($this->option('dataset')) {
                    $report['dataset'] = $datasetVerifier->verify($contents);
                    $report['valid'] = $report['dataset']['valid'];
                    if (! $report['valid']) {
                        $report['error'] = 'Written dataset failed portable verification.';
                    }
                }
            }
        } catch (Throwable $exception) {
            $report = [
                'schema_version' => 1,
                'valid' => false,
                'error' => $exception->getMessage(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($report['valid']) {
            $this->info("Audit evidence written to {$report['disk']}:{$report['path']} ({$report['evidence_hash']}).");
            if (isset($report['dataset']) && ! $report['dataset']['export_event_anchored']) {
                $this->warn('The export commitment is not covered by an included external anchor. Retain its hash independently.');
            }
        } else {
            $this->error("Audit-evidence export failed: {$report['error']}");
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    private function path(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f]/', $path)) {
            throw new AuditEvidenceException('Audit-evidence output path must be a safe relative path.');
        }
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new AuditEvidenceException('Audit-evidence output path must be a safe relative path.');
        }

        return $path;
    }
}
