<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Settlement;
use FilamentAccounting\Support\MoneyFormatter;

final class DocumentSettlementActions
{
    /** @return list<Action> */
    public static function make(Document $document): array
    {
        return $document->settlements
            ->map(function (Settlement $settlement): ?Action {
                $line = $settlement->reconciliation?->statementLine;
                if (! $line) {
                    return null;
                }

                try {
                    $url = ReconciliationPage::getUrl(['line' => $line->uuid]);
                } catch (\Throwable) {
                    return null;
                }

                return Action::make('bankTransaction'.$settlement->getKey())
                    ->label(__('filament-accounting::actions.open_bank_transaction', [
                        'amount' => MoneyFormatter::format((int) $settlement->amount_minor, $settlement->currency),
                        'date' => $line->booking_date?->toDateString() ?? '—',
                    ]))
                    ->icon('heroicon-o-banknotes')
                    ->url($url);
            })
            ->filter()
            ->values()
            ->all();
    }
}
