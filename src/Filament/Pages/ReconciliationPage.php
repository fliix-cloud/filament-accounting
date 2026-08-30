<?php

namespace FilamentAccounting\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\FinalizeReconciliation;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class ReconciliationPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $slug = 'accounting/reconcile';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament-accounting::pages.reconciliation';

    #[Url]
    public ?string $line = null;

    /** @var list<array<string, mixed>> */
    public array $splits = [];

    public ?string $exceptionReason = null;

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.reconciliation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament-accounting::navigation.group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-accounting::navigation.reconciliation');
    }

    public static function canAccess(): bool
    {
        return app(AccountingAuthorizer::class)->can('draft_reconciliation');
    }

    public function mount(): void
    {
        if ($this->line) {
            $this->hydrateSuggestions();
        }
    }

    public function statementLine(): ?BankStatementLine
    {
        if (! filled($this->line)) {
            return null;
        }

        try {
            $entity = app(LegalEntityScope::class)->require();
        } catch (\Throwable) {
            return null;
        }

        return BankStatementLine::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('uuid', $this->line)
            ->with(['bankAccount', 'reconciliations.splits'])
            ->first();
    }

    public function remainingMinor(): int
    {
        $line = $this->statementLine();
        if (! $line) {
            return 0;
        }

        $sum = 0;
        foreach ($this->splits as $split) {
            $sum += (int) ($split['amount_minor'] ?? 0);
        }

        return (int) $line->amount_minor - $sum;
    }

    public function addSplit(): void
    {
        $this->splits[] = [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'amount_minor' => $this->remainingMinor(),
            'open_item_id' => null,
            'ledger_account_id' => null,
            'reason' => null,
        ];
    }

    public function removeSplit(int $index): void
    {
        unset($this->splits[$index]);
        $this->splits = array_values($this->splits);
    }

    public function finalize(FinalizeReconciliation $finalizer): void
    {
        $line = $this->statementLine();
        if (! $line) {
            return;
        }

        if ($this->remainingMinor() !== 0) {
            Notification::make()
                ->danger()
                ->title(__('filament-accounting::validation.splits_must_balance'))
                ->send();

            return;
        }

        $finalizer->handle($line, $this->splits, $this->exceptionReason ?: null);
        Notification::make()
            ->success()
            ->title(__('filament-accounting::notifications.reconciliation_finalized'))
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $line = $this->statementLine();
        $suggestions = [];
        if ($line) {
            $suggestions = app(SuggestReconciliationMatches::class)->handle($line);
        }

        $openItems = [];
        if ($line) {
            $openItems = OpenItem::query()
                ->where('legal_entity_id', $line->legal_entity_id)
                ->where('is_reversed', false)
                ->with('document')
                ->get()
                ->filter(fn (OpenItem $item): bool => $item->remainingMinor() !== 0);
        }

        return [
            'statementLine' => $line,
            'suggestions' => $suggestions,
            'openItems' => $openItems,
            'remaining' => $line ? MoneyFormatter::format($this->remainingMinor(), $line->currency) : null,
            'formattedAmount' => $line ? MoneyFormatter::format((int) $line->amount_minor, $line->currency) : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addSplit')
                ->label(__('filament-accounting::actions.add_split'))
                ->action(fn () => $this->addSplit()),
            Action::make('finalize')
                ->label(__('filament-accounting::actions.finalize'))
                ->visible(fn (): bool => app(AccountingAuthorizer::class)->can('finalize_reconciliation'))
                ->requiresConfirmation()
                ->action(fn (FinalizeReconciliation $finalizer) => $this->finalize($finalizer)),
        ];
    }

    private function hydrateSuggestions(): void
    {
        $line = $this->statementLine();
        if (! $line || $this->splits !== []) {
            return;
        }

        $suggestions = app(SuggestReconciliationMatches::class)->handle($line);
        if (count($suggestions) === 1 && ! $suggestions[0]->ambiguous) {
            $this->splits = [[
                'purpose' => SplitPurpose::SettleOpenItem->value,
                'amount_minor' => $line->amount_minor,
                'open_item_id' => $suggestions[0]->targetId,
                'ledger_account_id' => null,
                'reason' => implode(',', $suggestions[0]->reasons),
            ]];
        }
    }
}
