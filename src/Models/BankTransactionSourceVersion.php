<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bank_transaction_id
 * @property int|null $import_run_id
 * @property int $version
 * @property string $source_id
 * @property string $source_fingerprint
 * @property string $source_status
 * @property string $source_hash
 * @property array<string, mixed> $normalized_payload
 * @property string|null $raw_payload
 */
class BankTransactionSourceVersion extends AccountingModel
{
    use BelongsToLegalEntity;

    protected $table = 'accounting_bank_transaction_source_versions';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new PostedRecordImmutableException('Bank transaction source versions are append-only.');
        });
        static::deleting(static function (): never {
            throw new PostedRecordImmutableException('Bank transaction source versions are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'normalized_payload' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_transaction_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(BankImportRun::class, 'import_run_id');
    }
}
