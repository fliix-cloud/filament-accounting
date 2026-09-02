<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $display_name
 * @property string|null $iban
 * @property string|null $bic
 * @property string $currency
 * @property int $ledger_account_id
 * @property string $driver_key
 * @property string $external_account_id
 * @property bool $is_active
 * @property bool $ledger_mapping_confirmed
 * @property-read LedgerAccount $ledgerAccount
 */
class AccountingBankAccount extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_bank_accounts';

    protected $fillable = [
        'legal_entity_id',
        'display_name',
        'iban',
        'bic',
        'currency',
        'ledger_account_id',
        'driver_key',
        'external_account_id',
        'is_active',
        'ledger_mapping_confirmed',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ledger_mapping_confirmed' => 'boolean',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_account_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(BankImportRun::class, 'bank_account_id');
    }

    public function pickerLabel(): string
    {
        $iban = $this->formattedIban();

        return filled($this->display_name)
            ? $this->display_name.' · '.$iban
            : $iban;
    }

    public function formattedIban(): string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $this->iban));

        if ($iban === '') {
            return '#'.$this->getKey();
        }

        return trim(chunk_split($iban, 4, ' '));
    }

    /**
     * @param  Builder<AccountingBankAccount>  $query
     * @return Builder<AccountingBankAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<AccountingBankAccount>  $query
     * @return Builder<AccountingBankAccount>
     */
    public function scopeWithPendingStatementLineSummary(Builder $query): Builder
    {
        $accounts = $query->getModel()->getTable();
        $statementLines = (new BankStatementLine)->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select($accounts.'.*');
        }

        return $query->addSelect([
            'pending_statement_line_count' => BankStatementLine::query()
                ->selectRaw('count(*)')
                ->whereColumn($statementLines.'.bank_account_id', $accounts.'.id')
                ->where('source_status', 'pending'),
            'pending_statement_line_sum_minor' => BankStatementLine::query()
                ->selectRaw('coalesce(sum(amount_minor), 0)')
                ->whereColumn($statementLines.'.bank_account_id', $accounts.'.id')
                ->where('source_status', 'pending'),
        ]);
    }

    /**
     * @return array{count: int, sum_minor: int}
     */
    public function pendingStatementLinesSummary(): array
    {
        if (array_key_exists('pending_statement_line_count', $this->attributes)
            && array_key_exists('pending_statement_line_sum_minor', $this->attributes)) {
            return [
                'count' => (int) $this->attributes['pending_statement_line_count'],
                'sum_minor' => (int) $this->attributes['pending_statement_line_sum_minor'],
            ];
        }

        $summary = $this->statementLines()
            ->where('source_status', 'pending')
            ->toBase()
            ->selectRaw('count(*) as pending_count, coalesce(sum(amount_minor), 0) as pending_sum_minor')
            ->first();

        return [
            'count' => (int) ($summary->pending_count ?? 0),
            'sum_minor' => (int) ($summary->pending_sum_minor ?? 0),
        ];
    }

    public function formattedPendingStatementLinesAmount(): ?string
    {
        $summary = $this->pendingStatementLinesSummary();

        return $summary['count'] > 0
            ? MoneyFormatter::format($summary['sum_minor'], $this->currency)
            : null;
    }
}
