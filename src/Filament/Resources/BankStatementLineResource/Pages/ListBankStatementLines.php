<?php

namespace FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithBankAccountSync;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Models\AccountingBankAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ListBankStatementLines extends ListRecords
{
    use InteractsWithBankAccountSync;

    protected static string $resource = BankStatementLineResource::class;

    #[Url(as: 'account')]
    public int|string|null $accountId = null;

    public function mount(): void
    {
        parent::mount();
        $this->resolveAccountId();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament-accounting::pages.bank-transaction-account-select')
                    ->viewData(fn (): array => [
                        'accounts' => $this->selectableAccounts(),
                        'selectedAccountId' => $this->accountId,
                        'selectedAccount' => $this->selectedAccount(),
                        'summary' => $this->selectedAccountSummary(),
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $this->constrainToSelectedAccount($query));
    }

    public function selectedAccount(): ?AccountingBankAccount
    {
        if (! $this->hasSelectedAccount()) {
            return null;
        }

        $account = $this->selectableAccounts()->firstWhere('id', (int) $this->accountId);

        return $account instanceof AccountingBankAccount ? $account : null;
    }

    /**
     * @return array{booked_balance: ?string, available_amount: ?string, pending_count: int, pending_amount: ?string, pending_amount_color: ?string}
     */
    public function selectedAccountSummary(): array
    {
        $account = $this->selectedAccount();
        $pending = $account?->pendingStatementLinesSummary() ?? ['count' => 0, 'sum_minor' => 0];

        return [
            'booked_balance' => $account?->formattedBalance($account->booked_balance_minor),
            'available_amount' => $account?->formattedBalance($account->available_amount_minor),
            'pending_count' => $pending['count'],
            'pending_amount' => $account?->formattedPendingStatementLinesAmount(),
            'pending_amount_color' => match (true) {
                $pending['sum_minor'] < 0 => '#0072B2',
                $pending['sum_minor'] > 0 => '#009E73',
                default => null,
            },
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncTransactionsAndBalance')
                ->label(__('filament-accounting::banking/fints/actions.sync_transactions_and_balance'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->selectedAccount()?->bank_connection_id !== null)
                ->disabled(fn (): bool => ! ProductRegistration::isConfigured() || ! $this->selectedAccount()?->isUsable())
                ->tooltip(fn (): ?string => ProductRegistration::isConfigured()
                    ? null
                    : __('filament-accounting::banking/fints/notifications.product_id_missing'))
                ->action(function (): void {
                    $account = $this->selectedAccount();
                    if ($account instanceof AccountingBankAccount) {
                        $this->syncBankAccountTransactionsAndBalance($account);
                    }
                }),
        ];
    }

    public function constrainToSelectedAccount(Builder $query): Builder
    {
        if (! $this->hasSelectedAccount()) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('bank_account_id', (int) $this->accountId);
    }

    public function updatedAccountId(mixed $value): void
    {
        $this->accountId = filled($value) ? (int) $value : null;
        $this->rememberAccountId();
        $this->resetTable();
    }

    public function hasSelectedAccount(): bool
    {
        return filled($this->accountId)
            && $this->selectableAccounts()->contains('id', (int) $this->accountId);
    }

    /**
     * @return Collection<int, AccountingBankAccount>
     */
    public function selectableAccounts(): Collection
    {
        return AccountingBankAccount::query()
            ->withPendingStatementLineSummary()
            ->active()
            ->orderBy('iban')
            ->get();
    }

    private function resolveAccountId(): void
    {
        $accounts = $this->selectableAccounts();
        $requested = $this->accountId
            ?? data_get($this->tableFilters, 'bank_account_id.value')
            ?? session('filament-accounting.bank_transactions_account_id');

        if (filled($requested) && $accounts->contains('id', (int) $requested)) {
            $this->accountId = (int) $requested;
            $this->rememberAccountId();

            return;
        }

        if ($accounts->count() === 1) {
            $this->accountId = (int) $accounts->first()->id;
            $this->rememberAccountId();

            return;
        }

        $this->accountId = null;
    }

    private function rememberAccountId(): void
    {
        session(['filament-accounting.bank_transactions_account_id' => $this->accountId]);
    }
}
