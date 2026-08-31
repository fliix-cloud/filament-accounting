<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Enums\SplitPurpose;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reconciliation_id
 * @property SplitPurpose $purpose
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $open_item_id
 * @property int|null $posting_rule_version_id
 * @property int|null $ledger_account_id
 * @property string|null $reason
 * @property-read OpenItem|null $openItem
 * @property-read PostingRuleVersion|null $postingRuleVersion
 * @property-read LedgerAccount|null $ledgerAccount
 */
class ReconciliationSplit extends AccountingModel
{
    protected $table = 'accounting_reconciliation_splits';

    protected $fillable = [
        'reconciliation_id',
        'purpose',
        'amount_minor',
        'currency',
        'open_item_id',
        'posting_rule_version_id',
        'ledger_account_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => SplitPurpose::class,
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Reconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    /** @return BelongsTo<OpenItem, $this> */
    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }

    /** @return BelongsTo<PostingRuleVersion, $this> */
    public function postingRuleVersion(): BelongsTo
    {
        return $this->belongsTo(PostingRuleVersion::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
