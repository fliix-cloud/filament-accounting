<?php

namespace FilamentAccounting\Support;

use Illuminate\Support\Facades\Storage;

final class InvoiceLogo
{
    public static function data(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        $disk = Storage::disk(config('filament-accounting.storage.disk', 'local'));
        if (! $disk->exists($path) || $disk->size($path) > 2 * 1024 * 1024) {
            return null;
        }
        $bytes = $disk->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
