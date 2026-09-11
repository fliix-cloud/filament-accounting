<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Detects broken attachment references and orphaned files without modifying storage. */
class StorageIntegrityCommand extends Command
{
    protected $signature = 'filament-accounting:storage-integrity
        {--entity= : Limit checks to one legal-entity UUID}
        {--scan-disk : Also scan the storage directory for files not referenced by any DB record (can be slow)}
        {--json : Emit a machine-readable report}';

    protected $description = 'Check attachment and intake file references for missing files and orphans';

    public function handle(): int
    {
        $issues = [];
        $entityConstraint = $this->option('entity');
        $entity = null;

        // 1. Attachment rows whose file is missing.
        $attachmentQuery = Attachment::query();
        if ($entityConstraint) {
            $entity = LegalEntity::query()->where('uuid', $entityConstraint)->first();
            if (! $entity instanceof LegalEntity) {
                $this->error('Legal entity not found.');

                return self::FAILURE;
            }
            $attachmentQuery->where('legal_entity_id', $entity->getKey());
        }
        $attachmentQuery->chunk(500, function (Collection $attachments) use (&$issues): void {
            foreach ($attachments as $attachment) {
                /** @var Attachment $attachment */
                if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
                    $issues[] = [
                        'type' => 'attachment_missing',
                        'attachment_id' => $attachment->getKey(),
                        'disk' => $attachment->disk,
                        'path' => $attachment->path,
                        'original_filename' => $attachment->original_filename,
                        'legal_entity_id' => $attachment->legal_entity_id,
                    ];
                }
            }
        });

        // 2. Intake files whose stored blob is missing.
        $intakeQuery = PurchaseInvoiceIntake::query()->whereNotNull('preserved_at');
        if ($entityConstraint && $entity instanceof LegalEntity) {
            $intakeQuery->where('legal_entity_id', $entity->getKey());
        }
        $intakeQuery->chunk(200, function (Collection $intakes) use (&$issues): void {
            foreach ($intakes as $intake) {
                foreach (($intake->files ?? []) as $role => $file) {
                    if (! Storage::disk($intake->disk)->exists($file['path'])) {
                        $issues[] = [
                            'type' => 'intake_missing',
                            'intake_id' => $intake->getKey(),
                            'role' => $role,
                            'disk' => $intake->disk,
                            'path' => $file['path'],
                            'filename' => $file['filename'] ?? 'unknown',
                            'legal_entity_id' => $intake->legal_entity_id,
                        ];
                    }
                }
            }
        });

        // 3. Filesystem scan for orphaned files (optional, expensive).
        $orphanCount = 0;
        if ($this->option('scan-disk')) {
            $diskName = config('filament-accounting.storage.disk', 'local');
            $directory = trim(config('filament-accounting.storage.attachments_directory', 'accounting/attachments'), '/');
            $knownPaths = $this->knownAttachmentPaths($entityConstraint);
            $files = Storage::disk($diskName)->allFiles($directory);
            foreach ($files as $path) {
                $absolute = $diskName.'://'.$path;
                if (! isset($knownPaths[$absolute])) {
                    $issues[] = [
                        'type' => 'orphaned_file',
                        'disk' => $diskName,
                        'path' => $path,
                    ];
                    $orphanCount++;
                }
            }
        }

        $report = [
            'schema_version' => 1,
            'issue_count' => count($issues),
            'scanned_disk' => (bool) $this->option('scan-disk'),
            'orphaned_file_count' => $orphanCount,
            'issues' => $issues,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['issue_count'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($issues === []) {
            $this->info('All attachment and intake file references are intact.');

            return self::SUCCESS;
        }

        $missingAttachments = count(array_filter($issues, fn ($i) => $i['type'] === 'attachment_missing'));
        $missingIntakes = count(array_filter($issues, fn ($i) => $i['type'] === 'intake_missing'));

        if ($missingAttachments > 0) {
            $this->error("{$missingAttachments} attachment row(s) refer to a missing file.");
        }
        if ($missingIntakes > 0) {
            $this->error("{$missingIntakes} intake file(s) are missing from storage.");
        }
        if ($orphanCount > 0) {
            $this->warn("{$orphanCount} orphaned file(s) found on disk with no matching database record.");
        }

        foreach ($issues as $issue) {
            if ($issue['type'] === 'orphaned_file') {
                $this->line("  <comment>ORPHAN</comment> {$issue['disk']}://{$issue['path']}");
            } elseif ($issue['type'] === 'attachment_missing') {
                $this->line("  <error>MISSING</error> attachment {$issue['attachment_id']}: {$issue['disk']}://{$issue['path']} ({$issue['original_filename']})");
            } else {
                $this->line("  <error>MISSING</error> intake {$issue['intake_id']}/{$issue['role']}: {$issue['disk']}://{$issue['path']}");
            }
        }

        return self::FAILURE;
    }

    /** @return array<string, true> */
    private function knownAttachmentPaths(?string $entityUuid): array
    {
        $query = Attachment::query()->select('disk', 'path');
        if ($entityUuid) {
            $query->whereHas('legalEntity', fn ($q) => $q->where('uuid', $entityUuid));
        }
        $paths = [];
        foreach ($query->lazy(500) as $att) {
            $paths[$att->disk.'://'.$att->path] = true;
        }

        return $paths;
    }
}
