<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreAttachment
{
    public function __construct(
        private readonly AccountingActorResolver $actors,
    ) {}

    /** @param array<string, mixed> $meta */
    public function handle(LegalEntity $entity, Model $attachable, string $filename, string $contents, string $sourceType = 'upload', array $meta = []): Attachment
    {
        $disk = (string) config('filament-accounting.storage.disk', 'local');

        if ($disk === 'public') {
            throw new AccountingException(__('filament-accounting::errors.public_disk_forbidden'));
        }

        $this->assertAttachable($entity, $attachable);
        $filename = $this->validateFilenameAndContents($filename, $contents);
        $hash = hash('sha256', $contents);
        $mime = $this->detectMime($contents, $filename);
        $this->validateSignature($mime, $contents);

        $existing = Attachment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->where('sha256', $hash)
            ->where('source_type', $sourceType)
            ->first();
        if ($existing instanceof Attachment) {
            if (! Storage::disk($existing->disk)->exists($existing->path)) {
                $this->writeAndVerify($existing->disk, $existing->path, $contents, $hash);
            }

            return $existing;
        }

        $directory = trim((string) config('filament-accounting.storage.attachments_directory', 'accounting/attachments'), '/');
        $basename = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'attachment';
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $path = $directory.'/'.$entity->uuid.'/'.$hash.'-'.$basename.'.'.$extension;
        $written = false;

        try {
            $this->writeAndVerify($disk, $path, $contents, $hash);
            $written = true;

            return DB::transaction(function () use ($entity, $attachable, $filename, $contents, $sourceType, $meta, $hash, $mime, $disk, $path): Attachment {
                $actor = $this->actors->resolve();
                $attachment = new Attachment;
                $attachment->fill([
                    'legal_entity_id' => $entity->getKey(),
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'original_filename' => $filename,
                    'mime_type' => $mime,
                    'size' => strlen($contents),
                    'sha256' => $hash,
                    'disk' => $disk,
                    'path' => $path,
                    'source_type' => $sourceType,
                    'structured_payload' => str_contains($mime, 'xml') ? $contents : null,
                    'meta' => $meta,
                    'uploaded_by_type' => $actor?->getMorphClass(),
                    'uploaded_by_id' => $actor ? (string) $actor->getKey() : null,
                ]);
                $attachment->save();

                return $attachment;
            });
        } catch (\Throwable $exception) {
            if ($written) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    private function assertAttachable(LegalEntity $entity, Model $attachable): void
    {
        if (! $attachable->exists || $attachable->getKey() === null) {
            throw new AccountingException(__('filament-accounting::errors.attachment_requires_persisted_model'));
        }

        $legalEntityId = $attachable instanceof LegalEntity
            ? $attachable->getKey()
            : $attachable->getAttribute('legal_entity_id');
        if ((string) $legalEntityId !== (string) $entity->getKey()) {
            throw new AccountingException(__('filament-accounting::errors.entity_mismatch'));
        }
    }

    private function validateFilenameAndContents(string $filename, string $contents): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $maximum = (int) config('filament-accounting.storage.maximum_attachment_bytes', 15 * 1024 * 1024);
        if ($filename === '' || str_contains($filename, "\0") || $contents === '' || strlen($contents) > $maximum) {
            throw new AccountingException(__('filament-accounting::errors.invalid_attachment'));
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'xml', 'png', 'jpg', 'jpeg'], true)) {
            throw new AccountingException(__('filament-accounting::errors.unsupported_attachment_type'));
        }

        return $filename;
    }

    private function validateSignature(string $mime, string $contents): void
    {
        if ($mime === 'application/pdf' && ! str_starts_with($contents, '%PDF-')) {
            throw new AccountingException(__('filament-accounting::errors.invalid_pdf'));
        }

        if (str_contains($mime, 'xml')) {
            if (stripos($contents, '<!DOCTYPE') !== false) {
                throw new AccountingException(__('filament-accounting::errors.unsafe_xml'));
            }

            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $valid = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $valid) {
                throw new AccountingException(__('filament-accounting::errors.invalid_xml'));
            }
        }
    }

    private function writeAndVerify(string $disk, string $path, string $contents, string $hash): void
    {
        if (! Storage::disk($disk)->put($path, $contents, ['visibility' => 'private'])) {
            throw new AccountingException(__('filament-accounting::errors.attachment_write_failed'));
        }

        $stored = Storage::disk($disk)->get($path);
        if (hash('sha256', $stored) !== $hash) {
            Storage::disk($disk)->delete($path);
            throw new AccountingException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
    }

    private function detectMime(string $contents, string $filename): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents) ?: null;

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'xml' => in_array($detected, ['application/xml', 'text/xml'], true) ? 'application/xml' : throw new AccountingException(__('filament-accounting::errors.invalid_xml')),
            'pdf' => $detected === 'application/pdf' ? 'application/pdf' : throw new AccountingException(__('filament-accounting::errors.invalid_pdf')),
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => $detected ?: 'application/octet-stream',
        };
    }
}
