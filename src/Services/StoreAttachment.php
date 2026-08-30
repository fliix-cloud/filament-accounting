<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreAttachment
{
    public function __construct(
        private readonly AccountingActorResolver $actors,
    ) {}

    public function handle(LegalEntity $entity, Model $attachable, string $filename, string $contents, string $sourceType = 'upload'): Attachment
    {
        $disk = (string) config('filament-accounting.storage.disk', 'local');

        if ($disk === 'public') {
            throw new AccountingException(__('filament-accounting::errors.public_disk_forbidden'));
        }

        $hash = hash('sha256', $contents);
        $mime = $this->detectMime($contents, $filename);
        $directory = trim((string) config('filament-accounting.storage.attachments_directory', 'accounting/attachments'), '/');
        $path = $directory.'/'.$entity->uuid.'/'.$hash.'-'.Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.'.pathinfo($filename, PATHINFO_EXTENSION);

        Storage::disk($disk)->put($path, $contents);

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
            'uploaded_by_type' => $actor?->getMorphClass(),
            'uploaded_by_id' => $actor ? (string) $actor->getKey() : null,
        ]);
        $attachment->save();

        return $attachment;
    }

    private function detectMime(string $contents, string $filename): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents) ?: null;

        if (is_string($detected) && $detected !== 'application/octet-stream') {
            return $detected;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => $detected ?: 'application/octet-stream',
        };
    }
}
