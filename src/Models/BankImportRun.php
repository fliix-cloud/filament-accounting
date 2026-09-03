<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int|null $bank_account_id
 * @property string $source
 * @property int $upserted_count
 * @property string|null $cursor
 * @property array<string, mixed>|null $meta
 */
class BankImportRun extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_bank_import_runs';

    protected $fillable = [
        'legal_entity_id',
        'bank_account_id',
        'source',
        'upserted_count',
        'cursor',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'upserted_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingBankAccount::class, 'bank_account_id');
    }
}
