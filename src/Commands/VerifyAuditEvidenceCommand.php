<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditEvidenceVerifier;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

class VerifyAuditEvidenceCommand extends Command
{
    protected $signature = 'filament-accounting:audit-verify-file
        {path : Relative evidence path on the selected filesystem disk}
        {--disk= : Laravel filesystem disk; defaults to the package storage disk}
        {--json : Emit a machine-readable verification report}';

    protected $description = 'Verify an exported audit-evidence document without reading accounting tables';

    public function handle(
        AuditEvidenceVerifier $verifier,
        FilesystemFactory $filesystems,
    ): int {
        try {
            $path = $this->path((string) $this->argument('path'));
            $diskName = (string) ($this->option('disk') ?: config('filament-accounting.storage.disk', 'local'));
            $disk = $filesystems->disk($diskName);

            if (! $disk->exists($path)) {
                throw new AuditEvidenceException("Audit-evidence file [{$path}] does not exist on disk [{$diskName}].");
            }

            $result = $verifier->verify($disk->get($path));
            $report = $result->toArray();
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
            $this->info("Offline audit-evidence verification passed ({$report['evidence_hash']}).");
        } else {
            $this->error('Offline audit-evidence verification failed.');

            if (isset($report['error'])) {
                $this->error($report['error']);
            } else {
                foreach (['evidence', 'audit_chain', 'external_anchors'] as $section) {
                    foreach ($report[$section]['issues'] as $issue) {
                        $sequence = $issue['sequence'] === null ? '' : " at sequence {$issue['sequence']}";
                        $this->error("{$section} [{$issue['code']}]{$sequence}: {$issue['message']}");
                    }
                }
            }
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    private function path(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new AuditEvidenceException('Audit-evidence input path must be a safe relative path.');
        }

        return $path;
    }
}
