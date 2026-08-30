<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property string $source_type
 * @property string|null $structured_payload
 * @property array<string, mixed>|null $meta
 */
class Attachment extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_attachments';

    protected $fillable = [
        'legal_entity_id',
        'attachable_type',
        'attachable_id',
        'original_filename',
        'mime_type',
        'size',
        'sha256',
        'disk',
        'path',
        'source_type',
        'structured_payload',
        'meta',
        'uploaded_by_type',
        'uploaded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'meta' => 'array',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
