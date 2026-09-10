<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditEvidenceVerifier;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Export\AccountingDatasetVerifier;
use FilamentAccounting\Export\StreamDatasetVerifier;
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
        AccountingDatasetVerifier $datasets,
        StreamDatasetVerifier $streamVerifier,
    ): int {
        try {
            $path = $this->path((string) $this->argument('path'));
            $diskName = (string) ($this->option('disk') ?: config('filament-accounting.storage.disk', 'local'));
            $disk = $filesystems->disk($diskName);

            if (! $disk->exists($path)) {
                throw new AuditEvidenceException("Audit-evidence file [{$path}] does not exist on disk [{$diskName}].");
            }

            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                throw new AuditEvidenceException('Cannot open evidence input stream.');
            }
            try {
                $prefix = fgets($stream, 128);
                if ($prefix === StreamDatasetVerifier::MAGIC) {
                    $report = $streamVerifier->verify($stream, magicConsumed: true);
                } else {
                    $contents = $prefix.stream_get_contents($stream);
                    $header = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    $report = ($header['format'] ?? null) === 'filament-accounting-dataset'
                        ? $datasets->verify($contents)
                        : $verifier->verify($contents)->toArray();
                }
            } finally {
                fclose($stream);
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
            $hash = $report['evidence_hash'] ?? $report['dataset_sha256'];
            $this->info("Offline audit-evidence verification passed ({$hash}).");
            if (isset($report['export_event_anchored']) && ! $report['export_event_anchored']) {
                $this->warn('The export commitment is not covered by an included external anchor. Retain its hash independently.');
            }
        } else {
            $this->error('Offline audit-evidence verification failed.');

            if (isset($report['error'])) {
                $this->error($report['error']);
            } elseif (isset($report['issues'])) {
                foreach ($report['issues'] as $issue) {
                    $this->error($issue);
                }
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
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f]/', $path)) {
            throw new AuditEvidenceException('Audit-evidence input path must be a safe relative path.');
        }
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new AuditEvidenceException('Audit-evidence input path must be a safe relative path.');
        }

        return $path;
    }
}
