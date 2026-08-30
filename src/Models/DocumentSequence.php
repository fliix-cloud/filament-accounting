<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;

/**
 * @property int $id
 * @property int $legal_entity_id
 * @property DocumentType $document_type
 * @property int $fiscal_year
 * @property int $next_number
 * @property string|null $prefix
 */
class DocumentSequence extends AccountingModel
{
    use BelongsToLegalEntity;

    protected $table = 'accounting_document_sequences';

    protected $fillable = [
        'legal_entity_id',
        'document_type',
        'fiscal_year',
        'next_number',
        'prefix',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'fiscal_year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
