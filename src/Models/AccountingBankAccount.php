<?php

namespace FilamentAccounting\Models;

use Fhp\Model\SEPAAccount;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\Services\BankLedgerAccountProvisioner;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int|null $bank_connection_id
 * @property string $display_name
 * @property string|null $iban
 * @property string|null $bic
 * @property string $currency
 * @property int $ledger_account_id
 * @property string $source
 * @property string $external_account_id
 * @property bool $is_active
 * @property bool $is_available
 * @property bool $is_enabled
 * @property string|null $fingerprint
 * @property string|null $account_number
 * @property string|null $sub_account
 * @property string|null $bank_code
 * @property string|null $product_name
 * @property string|null $account_holder_name
 * @property int|null $booked_balance_minor
 * @property int|null $pending_balance_minor
 * @property int|null $credit_line_minor
 * @property int|null $available_amount_minor
 * @property Carbon|null $balance_at
 * @property Carbon|null $last_balance_sync_at
 * @property Carbon|null $last_transaction_sync_at
 * @property-read BankConnection|null $connection
 * @property-read LedgerAccount $ledgerAccount
 */
class AccountingBankAccount extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_bank_accounts';

    protected $fillable = [
        'legal_entity_id',
        'bank_connection_id',
        'display_name',
        'iban',
        'bic',
        'currency',
        'ledger_account_id',
        'source',
        'external_account_id',
        'fingerprint',
        'account_number',
        'sub_account',
        'bank_code',
        'product_name',
        'account_holder_name',
        'is_available',
        'is_enabled',
        'booked_balance_minor',
        'pending_balance_minor',
        'credit_line_minor',
        'available_amount_minor',
        'balance_at',
        'last_balance_sync_at',
        'last_transaction_sync_at',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if ($account->bank_connection_id === null) {
                return;
            }

            $account->source = 'fints';
            $account->is_available ??= true;
            $account->is_enabled ??= true;
            $account->is_active = $account->is_available && $account->is_enabled;

            if ($account->ledger_account_id !== null) {
                return;
            }

            $entity = LegalEntity::query()->findOrFail($account->legal_entity_id);
            $ledger = app(BankLedgerAccountProvisioner::class)->provision(
                $entity,
                (string) ($account->external_account_id ?: $account->fingerprint ?: $account->uuid),
                (string) ($account->display_name ?: $account->iban ?: 'FinTS'),
                (string) ($account->currency ?: $entity->base_currency),
            );
            $account->ledger_account_id = $ledger->getKey();
        });

        static::saving(function (self $account): void {
            if ($account->bank_connection_id !== null) {
                $account->is_active = $account->is_available && $account->is_enabled;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'is_enabled' => 'boolean',
            'booked_balance_minor' => 'integer',
            'pending_balance_minor' => 'integer',
            'credit_line_minor' => 'integer',
            'available_amount_minor' => 'integer',
            'balance_at' => 'datetime',
            'last_balance_sync_at' => 'datetime',
            'last_transaction_sync_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
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

    public function transfers(): HasMany
    {
        return $this->hasMany(BankTransfer::class, 'accounting_bank_account_id');
    }

    public function directDebits(): HasMany
    {
        return $this->hasMany(BankDirectDebit::class, 'accounting_bank_account_id');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->is_available && $this->is_enabled;
    }

    public function toSepaAccount(): SEPAAccount
    {
        if (! $this->isUsable()) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }

        $account = new SEPAAccount;
        $account->setIban($this->iban);
        $account->setBic($this->bic);
        $account->setAccountNumber($this->account_number);
        $account->setSubAccount($this->sub_account);
        $account->setBlz($this->bank_code);

        return $account;
    }

    public function displayName(): string
    {
        return $this->display_name ?: $this->product_name ?: $this->maskedIban();
    }

    public function pickerLabel(): string
    {
        $iban = $this->formattedIban();
        $displayName = trim((string) $this->display_name);
        $normalizedDisplayName = strtoupper((string) preg_replace('/\s+/', '', $displayName));
        $normalizedIban = strtoupper((string) preg_replace('/\s+/', '', (string) $this->iban));

        return filled($displayName) && $normalizedDisplayName !== $normalizedIban
            ? $displayName.' · '.$iban
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

    public function maskedIban(): string
    {
        $iban = (string) $this->iban;

        if (strlen($iban) < 8) {
            return $iban !== '' ? str_repeat('*', strlen($iban)) : '—';
        }

        return substr($iban, 0, 4).str_repeat('*', max(strlen($iban) - 8, 0)).substr($iban, -4);
    }

    public function formattedBalance(?int $minor): ?string
    {
        return $minor === null ? null : MoneyFormatter::format($minor, $this->currency);
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
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn('is_available'), true)
            ->where($query->qualifyColumn('is_enabled'), true);
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
