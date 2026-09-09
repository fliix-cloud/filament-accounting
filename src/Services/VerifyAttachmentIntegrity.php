<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Models\Attachment;
use Illuminate\Support\Facades\Storage;

final class VerifyAttachmentIntegrity
{
    public function handle(Attachment $attachment): void
    {
        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        if (! is_string($contents) || strlen($contents) !== $attachment->size
            || ! hash_equals($attachment->sha256, hash('sha256', $contents))) {
            throw new AccountingException(__('filament-accounting::errors.attachment_integrity_failed'));
        }
    }
}
