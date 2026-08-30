<?php

namespace FilamentAccounting\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use FilamentAccounting\Enums\DerivedReconciliationBadge;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\MoneyFormatter;

class AccountingOverviewStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        try {
            $entity = app(LegalEntityScope::class)->require();
        } catch (\Throwable) {
            return [
                Stat::make(__('filament-accounting::fields.customer'), '—'),
                Stat::make(__('filament-accounting::fields.supplier'), '—'),
                Stat::make(__('filament-accounting::statuses.reconciliation.unassigned'), '—'),
                Stat::make(__('filament-accounting::statuses.payment.unpaid'), '—'),
            ];
        }

        $currency = (string) $entity->base_currency;

        $openReceivables = OpenItem::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('kind', 'receivable')
            ->where('is_reversed', false)
            ->get()
            ->sum(fn (OpenItem $item): int => $item->remainingMinor());

        $openPayables = OpenItem::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('kind', 'payable')
            ->where('is_reversed', false)
            ->get()
            ->sum(fn (OpenItem $item): int => $item->remainingMinor());

        $unassignedLines = BankStatementLine::query()
            ->where('legal_entity_id', $entity->getKey())
            ->with('reconciliations.splits')
            ->get()
            ->filter(fn (BankStatementLine $line): bool => $line->derivedBadge() === DerivedReconciliationBadge::Unassigned)
            ->count();

        $unpaidInvoices = Document::query()
            ->where('legal_entity_id', $entity->getKey())
            ->with('openItem.settlements')
            ->get()
            ->filter(fn (Document $document): bool => in_array($document->paymentStatus(), [PaymentStatus::Unpaid, PaymentStatus::PartiallyPaid], true))
            ->count();

        return [
            Stat::make(__('filament-accounting::fields.customer'), MoneyFormatter::format((int) $openReceivables, $currency)),
            Stat::make(__('filament-accounting::fields.supplier'), MoneyFormatter::format((int) $openPayables, $currency)),
            Stat::make(__('filament-accounting::statuses.reconciliation.unassigned'), (string) $unassignedLines),
            Stat::make(__('filament-accounting::statuses.payment.unpaid'), (string) $unpaidInvoices),
        ];
    }
}
