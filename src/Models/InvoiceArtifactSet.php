<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $legal_entity_id
 * @property int $document_id
 * @property string $disk
 * @property array<string, array{path: string, filename: string, sha256: string, size: int}> $manifest
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed> $meta
 * @property string $evidence_sha256
 * @property string $pdf_base64
 * @property string $xml
 * @property array<string, bool> $preserved_roles
 * @property Carbon|null $completed_at
 */
class InvoiceArtifactSet extends AccountingModel
{
    protected $table = 'accounting_invoice_artifact_sets';

    protected $guarded = [];

    protected $hidden = ['pdf_base64', 'xml'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'snapshot' => 'array', 'meta' => 'array', 'preserved_roles' => 'array', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $set): void {
            $stored = self::query()->findOrFail($set->getRawOriginal($set->getKeyName()));
            if ($set->isDirty(['id', 'legal_entity_id', 'document_id', 'disk', 'manifest', 'snapshot', 'meta', 'evidence_sha256', 'pdf_base64', 'xml'])
                || ($stored->completed_at !== null && $set->isDirty('completed_at'))) {
                throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
            }
            foreach ($stored->preserved_roles as $role => $preserved) {
                if ($preserved && ! ($set->preserved_roles[$role] ?? false)) {
                    throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
                }
            }
        });
        static::deleting(function (): void {
            throw new PostedRecordImmutableException(__('filament-accounting::errors.attachment_immutable'));
        });
    }
}
