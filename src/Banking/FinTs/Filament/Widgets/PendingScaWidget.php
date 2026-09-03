<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Widgets;

use Filament\Widgets\Widget;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;

class PendingScaWidget extends Widget
{
    protected string $view = 'filament-accounting::banking/fints/widgets.pending-sca';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $sessions = StrongAuthenticationSession::query()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->whereIn('state', ['needs_tan', 'needs_decoupled', 'needs_vop', 'needs_polling'])
            ->latest()
            ->limit(10)
            ->get();

        return ['sessions' => $sessions];
    }
}
