<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $open_item_id
 * @property int $journal_entry_id
 * @property int $amount_minor
 * @property string $currency
 * @property bool $is_reversed
 * @property int|null $reverses_id
 */
class Settlement extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_settlements';

    protected $fillable = [
        'legal_entity_id',
        'open_item_id',
        'journal_entry_id',
        'amount_minor',
        'currency',
        'is_reversed',
        'reverses_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'is_reversed' => 'boolean',
        ];
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}
