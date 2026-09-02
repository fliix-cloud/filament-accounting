<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\Storage;

final class ReadAttachment
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly LegalEntityScope $entities,
    ) {}

    public function handle(Attachment $attachment): string
    {
        $entity = $this->entities->require();
        $this->entities->assertSame($attachment->legal_entity_id, $entity);
        $this->authorizer->authorize('view', $attachment);

        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        if (strlen($contents) !== $attachment->size || hash('sha256', $contents) !== $attachment->sha256) {
            throw new AccountingException(__('filament-accounting::errors.attachment_integrity_failed'));
        }

        return $contents;
    }
}
