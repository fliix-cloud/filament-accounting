<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Exceptions\AuditAnchorException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use JsonException;
use Throwable;

final class FilesystemAuditAnchorStore implements AuditAnchorStore
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly CanonicalJson $canonicalJson,
    ) {}

    public function all(string $legalEntityUuid): array
    {
        $directory = $this->directory($legalEntityUuid);
        $disk = $this->disk();
        $anchors = [];

        foreach ($disk->files($directory) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }

            try {
                $data = json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($data)) {
                    throw new JsonException('External audit anchor must decode to an object.');
                }

                $anchor = AuditAnchor::fromArray($data);
            } catch (Throwable $exception) {
                throw new AuditAnchorException("Cannot read external audit anchor [{$path}]: {$exception->getMessage()}", previous: $exception);
            }

            if ($anchor->legalEntityUuid !== $legalEntityUuid || $path !== $this->path($anchor)) {
                throw new AuditAnchorException("External audit anchor path [{$path}] does not match its content.");
            }

            $anchors[] = $anchor;
        }

        usort($anchors, fn (AuditAnchor $left, AuditAnchor $right): int => [
            $left->lastSequence,
            $left->anchorHash,
        ] <=> [
            $right->lastSequence,
            $right->anchorHash,
        ]);

        return $anchors;
    }

    public function putOnce(AuditAnchor $anchor): void
    {
        if (! (bool) config('filament-accounting.audit.anchor.immutable_storage_attested', false)) {
            throw new AuditAnchorException(
                'Refusing to write an external audit anchor until immutable/versioned storage is attested in configuration.',
            );
        }

        $disk = $this->disk();
        $path = $this->path($anchor);
        $contents = $this->canonicalJson->encode($anchor->toArray());

        if ($disk->exists($path)) {
            if (! hash_equals($contents, $disk->get($path))) {
                throw new AuditAnchorException("External audit anchor [{$path}] already exists with different content.");
            }

            return;
        }

        if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
            throw new AuditAnchorException("External audit anchor [{$path}] could not be written.");
        }

        if (! hash_equals($contents, $disk->get($path))) {
            throw new AuditAnchorException("External audit anchor [{$path}] failed read-after-write verification.");
        }
    }

    private function disk(): Filesystem
    {
        try {
            return $this->filesystems->disk((string) config('filament-accounting.audit.anchor.disk', 'local'));
        } catch (Throwable $exception) {
            throw new AuditAnchorException("External audit anchor storage is unavailable: {$exception->getMessage()}", previous: $exception);
        }
    }

    private function directory(string $legalEntityUuid): string
    {
        if (! preg_match('/\A[0-9a-f-]{36}\z/i', $legalEntityUuid)) {
            throw new AuditAnchorException('Legal entity UUID is invalid for external audit anchor storage.');
        }

        $prefix = trim((string) config('filament-accounting.audit.anchor.prefix', 'accounting/audit-anchors'), '/\\');

        if ($prefix === '' || str_contains($prefix, '..')) {
            throw new AuditAnchorException('External audit anchor storage prefix is invalid.');
        }

        return "{$prefix}/{$legalEntityUuid}";
    }

    private function path(AuditAnchor $anchor): string
    {
        return sprintf(
            '%s/%020d-%s.json',
            $this->directory($anchor->legalEntityUuid),
            $anchor->lastSequence,
            $anchor->anchorHash,
        );
    }
}
