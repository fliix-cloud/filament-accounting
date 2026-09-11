<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $posting_rule_id
 * @property int $version
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string|null $direction
 * @property bool $requires_receipt
 * @property string|null $tax_code
 * @property array<string, mixed> $account_mappings
 * @property array<int, mixed> $line_templates
 * @property-read PostingRule|null $postingRule
 */
class PostingRuleVersion extends AccountingModel
{
    use HasUuid;

    protected $table = 'accounting_posting_rule_versions';

    public const IMMUTABLE_FIELDS = [
        'account_mappings',
        'direction',
        'line_templates',
        'tax_code',
    ];

    protected $fillable = [
        'posting_rule_id',
        'version',
        'valid_from',
        'valid_to',
        'direction',
        'requires_receipt',
        'tax_code',
        'account_mappings',
        'line_templates',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'requires_receipt' => 'boolean',
            'account_mappings' => 'array',
            'line_templates' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (array_intersect(array_keys($version->getDirty()), self::IMMUTABLE_FIELDS) === []) {
                return;
            }
            if (ReconciliationSplit::query()
                ->where('posting_rule_version_id', $version->getKey())
                ->exists()) {
                throw new PostedRecordImmutableException(
                    __('filament-accounting::errors.posting_rule_version_immutable')
                );
            }
        });
    }

    /** @return BelongsTo<PostingRule, $this> */
    public function postingRule(): BelongsTo
    {
        return $this->belongsTo(PostingRule::class);
    }
}
