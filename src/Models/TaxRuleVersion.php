<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tax_code_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property int $rate_bp
 * @property bool $recoverable
 * @property string|null $category
 * @property string|null $reason
 * @property array<string, mixed>|null $export_mapping
 */
class TaxRuleVersion extends AccountingModel
{
    use HasUuid;

    protected $table = 'accounting_tax_rule_versions';

    protected $fillable = [
        'tax_code_id',
        'valid_from',
        'valid_to',
        'rate_bp',
        'recoverable',
        'category',
        'reason',
        'export_mapping',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'rate_bp' => 'integer',
            'recoverable' => 'boolean',
            'export_mapping' => 'array',
        ];
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
