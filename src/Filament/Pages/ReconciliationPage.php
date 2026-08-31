<?php

namespace FilamentAccounting\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\SplitStatementLine;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Support\BankSourceLinkRegistry;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class ReconciliationPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'accounting/reconcile';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament-accounting::pages.reconciliation';

    #[Url]
    public ?string $line = null;

    #[Url]
    public string $mode = 'direct';

    public string $directPurpose = 'settle_open_item';

    public ?string $directOpenItemId = null;

    public ?string $directPostingRuleVersionId = null;

    public ?string $directLedgerAccountId = null;

    public ?string $directReason = null;

    /** @var list<array<string, mixed>> */
    public array $allocations = [];

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
        if (! in_array($this->mode, ['direct', 'split'], true)) {
            $this->mode = 'direct';
        }

        if (! $this->line) {
            return;
        }

        if ($this->mode === 'split') {
            $this->ensureSplitEditor();

            return;
        }

        $this->hydrateSuggestion();
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
            ->with([
                'bankAccount',
                'reconciliations.journalEntry',
                'reconciliations.splits.openItem.document.party',
                'reconciliations.splits.postingRuleVersion.postingRule',
                'reconciliations.splits.ledgerAccount',
            ])
            ->first();
    }

    public function remainingMinor(): int
    {
        $line = $this->statementLine();
        if (! $line) {
            return 0;
        }

        $sum = 0;
        foreach ($this->allocations as $allocation) {
            $amount = $this->minorFromInput($allocation['amount'] ?? null, $line->currency);
            if ($amount !== null) {
                $sum += $amount;
            }
        }

        return (int) $line->amount_minor - $sum;
    }

    public function hasInvalidAllocationAmounts(): bool
    {
        $line = $this->statementLine();
        if (! $line) {
            return true;
        }

        foreach ($this->allocations as $allocation) {
            if ($this->minorFromInput($allocation['amount'] ?? null, $line->currency) === null) {
                return true;
            }
        }

        return false;
    }

    public function chooseOpenItem(int $openItemId): void
    {
        $this->mode = 'direct';
        $this->directPurpose = SplitPurpose::SettleOpenItem->value;
        $this->directOpenItemId = (string) $openItemId;
    }

    public function switchToDirect(): void
    {
        $this->mode = 'direct';
    }

    public function switchToSplit(): void
    {
        $this->mode = 'split';
        $this->ensureSplitEditor();
    }

    public function addAllocation(): void
    {
        $line = $this->statementLine();
        if (! $line) {
            return;
        }

        $remaining = $this->remainingMinor();
        $this->allocations[] = [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'amount' => $remaining === 0 ? '' : ExactMoney::ofMinor($remaining, $line->currency)->decimalString(),
            'open_item_id' => null,
            'posting_rule_version_id' => null,
            'ledger_account_id' => null,
            'reason' => null,
        ];
    }

    public function removeAllocation(int $index): void
    {
        if (count($this->allocations) <= 2) {
            return;
        }

        unset($this->allocations[$index]);
        $this->allocations = array_values($this->allocations);
    }

    public function assign(AssignStatementLine $assigner): void
    {
        $line = $this->statementLine();
        if (! $line || $line->activePostedReconciliation() instanceof Reconciliation) {
            return;
        }

        try {
            $assigner->handle(
                $line,
                $this->directAssignment(),
                $this->exceptionReason ?: null,
            );
        } catch (\Throwable $exception) {
            $this->failure($exception);

            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-accounting::notifications.reconciliation_finalized'))
            ->send();
    }

    public function finalizeSplit(SplitStatementLine $splitter): void
    {
        $line = $this->statementLine();
        if (! $line || $line->activePostedReconciliation() instanceof Reconciliation) {
            return;
        }

        try {
            $allocations = $this->splitAllocations($line);
            $splitter->handle($line, $allocations, $this->exceptionReason ?: null);
        } catch (\Throwable $exception) {
            $this->failure($exception);

            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-accounting::notifications.reconciliation_finalized'))
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $line = $this->statementLine();
        $posted = $line?->activePostedReconciliation();
        $openItems = $line ? $this->openItems($line) : collect();
        $suggestions = [];

        if ($line && ! ($posted instanceof Reconciliation)) {
            $openItemIds = $openItems->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $suggestions = collect(app(SuggestReconciliationMatches::class)->handle($line))
                ->filter(fn ($suggestion): bool => in_array($suggestion->targetId, $openItemIds, true))
                ->take(5)
                ->all();
        }

        return [
            'statementLine' => $line,
            'postedReconciliation' => $posted,
            'postedAllocations' => $posted instanceof Reconciliation ? $this->postedAllocations($posted) : [],
            'suggestions' => $suggestions,
            'openItems' => $openItems,
            'postingRuleOptions' => $line ? $this->postingRuleOptions($line) : [],
            'ledgerAccountOptions' => $line ? $this->ledgerAccountOptions($line) : [],
            'purposeOptions' => $this->purposeOptions(),
            'sourceUrl' => $line ? app(BankSourceLinkRegistry::class)->url($line) : null,
            'remaining' => $line ? MoneyFormatter::format($this->remainingMinor(), $line->currency) : null,
            'formattedAmount' => $line ? MoneyFormatter::format((int) $line->amount_minor, $line->currency) : null,
            'transactionsUrl' => $this->bankTransactionsUrl(),
            'amountMismatch' => $this->directAssignmentAmountMismatch(),
            'amountMatch' => $posted instanceof Reconciliation ? $posted->amountMatches() : null,
        ];
    }

    public function directAssignmentAmountMismatch(): bool
    {
        if ($this->mode !== 'direct' || $this->directPurpose !== SplitPurpose::SettleOpenItem->value) {
            return false;
        }

        $line = $this->statementLine();
        $item = $this->selectedOpenItem();
        if (! $line || ! $item) {
            return false;
        }

        return abs((int) $line->amount_minor) !== abs($item->remainingMinor());
    }

    public function directAssignmentConfirmationBody(): ?string
    {
        $line = $this->statementLine();
        $item = $this->selectedOpenItem();
        if (! $line || ! $item || ! $this->directAssignmentAmountMismatch()) {
            return null;
        }

        return __('filament-accounting::fields.amount_mismatch_confirm', [
            'transaction' => MoneyFormatter::format((int) $line->amount_minor, $line->currency),
            'invoice' => MoneyFormatter::format($item->remainingMinor(), $item->currency),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $line = $this->statementLine();
        if (! $line) {
            return [];
        }

        $actions = [];
        $sourceUrl = app(BankSourceLinkRegistry::class)->url($line);
        if ($sourceUrl !== null) {
            $actions[] = Action::make('openSource')
                ->label(__('filament-accounting::actions.open_source'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url($sourceUrl)
                ->openUrlInNewTab();
        }

        if ($line->activePostedReconciliation() instanceof Reconciliation) {
            return $actions;
        }

        if ($this->mode === 'direct') {
            $actions[] = Action::make('splitTransaction')
                ->label(__('filament-accounting::actions.split_transaction'))
                ->color('gray')
                ->action(fn () => $this->switchToSplit());
            $actions[] = Action::make('assign')
                ->label(__('filament-accounting::actions.assign_and_post'))
                ->visible(fn (): bool => app(AccountingAuthorizer::class)->can('finalize_reconciliation'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->directAssignmentAmountMismatch()
                    ? __('filament-accounting::fields.amount_mismatch_heading')
                    : __('filament-accounting::actions.assign_and_post'))
                ->modalDescription(fn (): ?string => $this->directAssignmentConfirmationBody())
                ->modalIcon(fn (): ?string => $this->directAssignmentAmountMismatch()
                    ? 'heroicon-o-exclamation-triangle'
                    : null)
                ->action(fn (AssignStatementLine $assigner) => $this->assign($assigner));

            return $actions;
        }

        $actions[] = Action::make('directAssignment')
            ->label(__('filament-accounting::actions.assign_directly'))
            ->color('gray')
            ->action(fn () => $this->switchToDirect());
        $actions[] = Action::make('addAllocation')
            ->label(__('filament-accounting::actions.add_allocation'))
            ->color('gray')
            ->action(fn () => $this->addAllocation());
        $actions[] = Action::make('finalizeSplit')
            ->label(__('filament-accounting::actions.post_split'))
            ->visible(fn (): bool => app(AccountingAuthorizer::class)->can('finalize_reconciliation'))
            ->requiresConfirmation()
            ->action(fn (SplitStatementLine $splitter) => $this->finalizeSplit($splitter));

        return $actions;
    }

    private function hydrateSuggestion(): void
    {
        $line = $this->statementLine();
        if (! $line || $this->directOpenItemId !== null) {
            return;
        }

        $suggestions = app(SuggestReconciliationMatches::class)->handle($line);
        if (count($suggestions) === 1 && ! $suggestions[0]->ambiguous) {
            $this->directOpenItemId = (string) $suggestions[0]->targetId;
        }
    }

    private function ensureSplitEditor(): void
    {
        if ($this->allocations !== []) {
            return;
        }

        $line = $this->statementLine();
        if (! $line) {
            return;
        }

        $this->allocations[] = [
            'purpose' => $this->directPurpose,
            'amount' => '',
            'open_item_id' => $this->directOpenItemId,
            'posting_rule_version_id' => $this->directPostingRuleVersionId,
            'ledger_account_id' => $this->directLedgerAccountId,
            'reason' => $this->directReason,
        ];
        $this->allocations[] = [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'amount' => '',
            'open_item_id' => null,
            'posting_rule_version_id' => null,
            'ledger_account_id' => null,
            'reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function directAssignment(): array
    {
        $purpose = SplitPurpose::tryFrom($this->directPurpose);

        return [
            'purpose' => $this->directPurpose,
            'open_item_id' => $purpose === SplitPurpose::SettleOpenItem ? $this->directOpenItemId : null,
            'posting_rule_version_id' => $purpose === SplitPurpose::PostingRule ? $this->directPostingRuleVersionId : null,
            'ledger_account_id' => in_array($purpose, [SplitPurpose::LedgerAccount, SplitPurpose::Transfer], true)
                ? $this->directLedgerAccountId
                : null,
            'reason' => $this->directReason,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function splitAllocations(BankStatementLine $line): array
    {
        return array_map(function (array $allocation) use ($line): array {
            $purpose = SplitPurpose::tryFrom((string) ($allocation['purpose'] ?? ''));
            $amountMinor = $this->minorFromInput($allocation['amount'] ?? null, $line->currency);
            if ($amountMinor === null) {
                throw new ReconciliationException(__('filament-accounting::errors.invalid_allocation_amount'));
            }

            return [
                'purpose' => $allocation['purpose'] ?? null,
                'amount_minor' => $amountMinor,
                'open_item_id' => $purpose === SplitPurpose::SettleOpenItem
                    ? ($allocation['open_item_id'] ?? null)
                    : null,
                'posting_rule_version_id' => $purpose === SplitPurpose::PostingRule
                    ? ($allocation['posting_rule_version_id'] ?? null)
                    : null,
                'ledger_account_id' => in_array($purpose, [SplitPurpose::LedgerAccount, SplitPurpose::Transfer], true)
                    ? ($allocation['ledger_account_id'] ?? null)
                    : null,
                'reason' => $allocation['reason'] ?? null,
            ];
        }, $this->allocations);
    }

    private function minorFromInput(mixed $amount, string $currency): ?int
    {
        try {
            $value = trim((string) $amount);
            if (str_contains($value, ',') && ! str_contains($value, '.')) {
                $value = str_replace(',', '.', $value);
            }

            return ExactMoney::ofString($value, $currency)->minorAmount;
        } catch (InvalidMoneyException) {
            return null;
        }
    }

    private function selectedOpenItem(): ?OpenItem
    {
        $line = $this->statementLine();
        if (! $line || ! filled($this->directOpenItemId)) {
            return null;
        }

        return $this->openItems($line)->firstWhere('id', (int) $this->directOpenItemId);
    }

    private function bankTransactionsUrl(): ?string
    {
        try {
            return BankStatementLineResource::getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return Collection<int, OpenItem> */
    private function openItems(BankStatementLine $line): Collection
    {
        return OpenItem::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('kind', $line->isIncoming() ? OpenItemKind::Receivable : OpenItemKind::Payable)
            ->where('currency', strtoupper($line->currency))
            ->where('is_reversed', false)
            ->with(['document.party', 'party'])
            ->orderBy('due_on')
            ->get()
            ->filter(fn (OpenItem $item): bool => $item->remainingMinor() !== 0)
            ->values();
    }

    /** @return array<int, string> */
    private function postingRuleOptions(BankStatementLine $line): array
    {
        $date = ($line->booking_date ?? now())->toDateString();
        $options = [];

        foreach (PostingRule::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->orderBy('label')
            ->get() as $rule) {
            $version = $rule->versionOn($date);
            if ($version instanceof PostingRuleVersion) {
                $options[$version->getKey()] = $rule->label;
            }
        }

        return $options;
    }

    /** @return array<int, string> */
    private function ledgerAccountOptions(BankStatementLine $line): array
    {
        return LedgerAccount::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account): array => [
                $account->getKey() => $account->code.' · '.$account->name,
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function purposeOptions(): array
    {
        return [
            SplitPurpose::SettleOpenItem->value => __('filament-accounting::fields.invoice_or_bill'),
            SplitPurpose::PostingRule->value => __('filament-accounting::fields.posting_rule'),
            SplitPurpose::LedgerAccount->value => __('filament-accounting::fields.ledger_account'),
            SplitPurpose::BankFee->value => __('filament-accounting::fields.bank_fee'),
            SplitPurpose::Transfer->value => __('filament-accounting::fields.transfer'),
            SplitPurpose::Suspense->value => __('filament-accounting::fields.suspense'),
            SplitPurpose::Rounding->value => __('filament-accounting::fields.rounding'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function postedAllocations(Reconciliation $reconciliation): array
    {
        return $reconciliation->splits->map(function (ReconciliationSplit $split): array {
            return [
                'amount' => MoneyFormatter::format((int) $split->amount_minor, $split->currency),
                'purpose' => $this->purposeOptions()[$split->purpose->value] ?? $split->purpose->value,
                'target' => $this->allocationTargetLabel($split),
                'url' => $this->allocationTargetUrl($split),
                'reason' => $split->reason,
            ];
        })->all();
    }

    private function allocationTargetLabel(ReconciliationSplit $split): string
    {
        if ($split->openItem?->document) {
            $document = $split->openItem->document;
            $number = $document->number ?: $document->supplier_invoice_number ?: $document->uuid;

            return $number.' · '.($document->party?->displayLabel() ?? __('filament-accounting::fields.unknown_party'));
        }

        if ($split->postingRuleVersion?->postingRule) {
            return $split->postingRuleVersion->postingRule->label;
        }

        if ($split->ledgerAccount) {
            return $split->ledgerAccount->code.' · '.$split->ledgerAccount->name;
        }

        return $this->purposeOptions()[$split->purpose->value] ?? $split->purpose->value;
    }

    private function allocationTargetUrl(ReconciliationSplit $split): ?string
    {
        $document = $split->openItem?->document;
        if (! $document) {
            return null;
        }

        return match ($document->type) {
            DocumentType::SalesInvoice => SalesInvoiceResource::getUrl('view', ['record' => $document]),
            DocumentType::PurchaseInvoice => PurchaseInvoiceResource::getUrl('view', ['record' => $document]),
            default => null,
        };
    }

    private function failure(\Throwable $exception): void
    {
        $notification = Notification::make()
            ->danger()
            ->title(__('filament-accounting::notifications.reconciliation_failed'));

        if ($exception instanceof ReconciliationException) {
            $notification->body($exception->getMessage());
        } elseif ($exception instanceof InvalidMoneyException) {
            $notification->body(__('filament-accounting::errors.invalid_allocation_amount'));
        } else {
            report($exception);
        }

        $notification->send();
    }
}
