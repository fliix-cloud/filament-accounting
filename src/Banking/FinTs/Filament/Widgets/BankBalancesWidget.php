<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Widgets;

use Filament\Widgets\Widget;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;

class BankBalancesWidget extends Widget
{
    protected string $view = 'filament-accounting::banking/fints/widgets.balances';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $accounts = BankAccount::query()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->where('is_available', true)
            ->where('is_enabled', true)
            ->get();

        return ['accounts' => $accounts];
    }
}
