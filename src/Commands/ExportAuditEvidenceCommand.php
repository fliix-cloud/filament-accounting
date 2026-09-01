<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditEvidenceExporter;
use FilamentAccounting\Exceptions\AuditEvidenceException;
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
        {--json : Emit a machine-readable command report}';

    protected $description = 'Export a portable, offline-verifiable audit-evidence document';

    public function handle(
        AuditEvidenceExporter $exporter,
        FilesystemFactory $filesystems,
    ): int {
        try {
            $selector = (string) $this->argument('legal-entity');
            $path = $this->path((string) $this->argument('path'));
            $diskName = (string) ($this->option('disk') ?: config('filament-accounting.storage.disk', 'local'));
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

            $evidence = $exporter->build($entity);
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
                'evidence_hash' => $evidence['evidence_hash'],
            ];
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
        } else {
            $this->error("Audit-evidence export failed: {$report['error']}");
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    private function path(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new AuditEvidenceException('Audit-evidence output path must be a safe relative path.');
        }

        return $path;
    }
}
