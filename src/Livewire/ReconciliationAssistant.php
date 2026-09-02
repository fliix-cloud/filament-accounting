<?php

namespace FilamentAccounting\Livewire;

use Filament\Notifications\Notification;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Reconciliation\ReconciliationAssistantQuery;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\SplitStatementLine;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ReconciliationAssistant extends Component
{
    /** @var list<string> */
    private const ASSIGNMENT_TYPES = [
        'sales_invoice',
        'purchase_invoice',
        'posting_rule',
        'ledger_account',
        'split',
    ];

    /** @var list<string> */
    private const SPLIT_TYPES = [
        'sales_invoice',
        'purchase_invoice',
        'posting_rule',
        'ledger_account',
    ];

    public string $line;

    public string $context = 'page';

    public string $assignmentType = 'sales_invoice';

    public string $invoiceSearch = '';

    public string $postingRuleSearch = '';

    public bool $onlyOpen = true;

    public bool $amountNear = false;

    public ?int $selectedOpenItemId = null;

    public ?int $selectedPostingRuleVersionId = null;

    public ?int $selectedLedgerAccountId = null;

    public ?string $allocationReason = null;

    public ?string $exceptionReason = null;

    /** @var list<array<string, mixed>> */
    public array $allocations = [];

    public function mount(string $line, string $context = 'page'): void
    {
        $this->line = $line;
        $this->context = in_array($context, ['page', 'modal'], true) ? $context : 'page';

        try {
            $statementLine = $this->query()->statementLine($line);
        } catch (\Throwable) {
            $statementLine = null;
        }

        if ($statementLine instanceof BankStatementLine) {
            $this->assignmentType = $statementLine->isIncoming() ? 'sales_invoice' : 'purchase_invoice';
        }
    }

    public function selectAssignmentType(string $type): void
    {
        if (! in_array($type, self::ASSIGNMENT_TYPES, true)) {
            return;
        }

        $this->resetErrorBag();
        $this->assignmentType = $type;
        $this->selectedOpenItemId = null;
        $this->selectedPostingRuleVersionId = null;
        $this->selectedLedgerAccountId = null;

        if ($type === 'split' && $this->allocations === []) {
            $line = $this->statementLine();
            $defaultType = $line?->isIncoming() ? 'sales_invoice' : 'purchase_invoice';
            $this->allocations = [
                $this->newAllocation($defaultType),
                $this->newAllocation($defaultType),
            ];
        }
    }

    public function selectOpenItem(int $id): void
    {
        $this->selectedOpenItemId = $id;
        $this->resetErrorBag();
    }

    public function selectPostingRule(int $id): void
    {
        $this->selectedPostingRuleVersionId = $id;
        $this->resetErrorBag();
    }

    public function selectLedgerAccount(int $id): void
    {
        $this->selectedLedgerAccountId = $id;
        $this->resetErrorBag();
    }

    public function addAllocation(): void
    {
        $line = $this->statementLine();
        $type = $line?->isIncoming() ? 'sales_invoice' : 'purchase_invoice';
        $this->allocations[] = $this->newAllocation($type);
    }

    public function removeAllocation(int $index): void
    {
        if (! array_key_exists($index, $this->allocations)) {
            return;
        }

        unset($this->allocations[$index]);
        $this->allocations = array_values($this->allocations);
    }

    public function changeAllocationType(int $index, string $type): void
    {
        if (! array_key_exists($index, $this->allocations) || ! in_array($type, self::SPLIT_TYPES, true)) {
            return;
        }

        $this->allocations[$index]['type'] = $type;
        $this->allocations[$index]['target_id'] = null;
    }

    public function useRemaining(int $index): void
    {
        $line = $this->statementLine();
        if (! $line instanceof BankStatementLine || ! array_key_exists($index, $this->allocations)) {
            return;
        }

        $this->allocations[$index]['amount'] = ExactMoney::ofMinor(
            $this->remainingMinor($line),
            $line->currency,
        )->decimalString();
    }

    public function cancel(): mixed
    {
        if ($this->context === 'modal') {
            $this->dispatch('reconciliation-assistant-cancelled');

            return null;
        }

        try {
            return $this->redirect(BankStatementLineResource::getUrl(), navigate: true);
        } catch (\Throwable) {
            return null;
        }
    }

    public function finalize(AssignStatementLine $assigner, SplitStatementLine $splitter): void
    {
        $this->resetErrorBag();
        $line = $this->statementLine();
        if (! $line instanceof BankStatementLine) {
            $this->addError('assistant', __('filament-accounting::errors.invalid_allocation_target'));

            return;
        }

        if ($line->activePostedReconciliation() instanceof Reconciliation) {
            $this->dispatch('reconciliation-assistant-finalized');

            return;
        }

        $errors = $this->validationErrors($line);
        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        try {
            if ($this->assignmentType === 'split') {
                $splitter->handle(
                    $line,
                    $this->splitAllocations($line),
                    $this->exceptionReason ?: null,
                );
            } else {
                $assigner->handle(
                    $line,
                    $this->directAssignment($line),
                    $this->exceptionReason ?: null,
                );
            }
        } catch (\Throwable $exception) {
            if (! $exception instanceof ReconciliationException && ! $exception instanceof InvalidMoneyException) {
                report($exception);
            }

            $this->addError(
                'assistant',
                $exception instanceof ReconciliationException
                    ? $exception->getMessage()
                    : __('filament-accounting::errors.invalid_allocation_amount'),
            );

            Notification::make()
                ->danger()
                ->title(__('filament-accounting::notifications.reconciliation_failed'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-accounting::notifications.reconciliation_finalized'))
            ->send();

        $this->dispatch('reconciliation-assistant-finalized');
    }

    public function render(): View
    {
        $line = $this->statementLine();
        $posted = $line?->activePostedReconciliation();
        $salesInvoices = [];
        $purchaseInvoices = [];
        $postingRules = [];
        $ledgerAccounts = [];
        $selectedOpenItem = null;
        $validationErrors = [];

        if ($line instanceof BankStatementLine && ! ($posted instanceof Reconciliation)) {
            $salesInvoices = $this->query()->invoiceCandidates(
                $line,
                OpenItemKind::Receivable,
                $this->invoiceSearch,
                $this->onlyOpen,
                $this->amountNear,
            );
            $purchaseInvoices = $this->query()->invoiceCandidates(
                $line,
                OpenItemKind::Payable,
                $this->invoiceSearch,
                $this->onlyOpen,
                $this->amountNear,
            );
            $postingRules = $this->query()->postingRuleCandidates($line, $this->postingRuleSearch);
            $ledgerAccounts = $this->query()->ledgerAccountCandidates($line);
            $selectedOpenItem = $this->query()->openItemCandidate($line, $this->selectedOpenItemId);
            $validationErrors = $this->validationErrors($line);
        }

        return view('filament-accounting::livewire.reconciliation-assistant', [
            'statementLine' => $line,
            'postedReconciliation' => $posted,
            'postedAllocations' => $posted instanceof Reconciliation ? $this->query()->postedAllocations($posted) : [],
            'salesInvoices' => $salesInvoices,
            'purchaseInvoices' => $purchaseInvoices,
            'postingRules' => $postingRules,
            'ledgerAccounts' => $ledgerAccounts,
            'selectedOpenItem' => $selectedOpenItem,
            'validationErrors' => $validationErrors,
            'canFinalize' => $validationErrors === [],
            'allocatedMinor' => $line ? $this->allocatedMinor($line) : 0,
            'remainingMinor' => $line ? $this->remainingMinor($line) : 0,
            'sourceUrl' => $line ? $this->query()->sourceUrl($line) : null,
            'counterpartyBic' => $line ? $this->payloadValue($line, ['counterparty_bic', 'bic']) : null,
            'bookingType' => $line ? $this->payloadValue($line, ['booking_type', 'transaction_type', 'type']) : null,
        ]);
    }

    private function statementLine(): ?BankStatementLine
    {
        try {
            return $this->query()->statementLine($this->line);
        } catch (\Throwable) {
            return null;
        }
    }

    private function query(): ReconciliationAssistantQuery
    {
        return app(ReconciliationAssistantQuery::class);
    }

    /** @return array<string, mixed> */
    private function newAllocation(string $type): array
    {
        return [
            'type' => $type,
            'target_id' => null,
            'amount' => '',
            'reason' => null,
        ];
    }

    /** @return array<string, string> */
    private function validationErrors(BankStatementLine $line): array
    {
        $errors = [];

        if ($line->source_status !== StatementLineStatus::Booked && ! filled($this->exceptionReason)) {
            $errors['exceptionReason'] = __('filament-accounting::errors.pending_cannot_finalize');
        }

        if ($this->assignmentType === 'split') {
            return $errors + $this->splitValidationErrors($line);
        }

        if (in_array($this->assignmentType, ['sales_invoice', 'purchase_invoice'], true)) {
            $expectedType = $line->isIncoming() ? 'sales_invoice' : 'purchase_invoice';
            if ($this->assignmentType !== $expectedType) {
                $errors['assignmentType'] = __('filament-accounting::errors.unsupported_invoice_direction');
            }

            $candidate = $this->query()->openItemCandidate($line, $this->selectedOpenItemId);
            $expectedKind = $this->assignmentType === 'sales_invoice' ? OpenItemKind::Receivable : OpenItemKind::Payable;
            if (! is_array($candidate) || ($candidate['kind'] ?? null) !== $expectedKind->value) {
                $errors['selectedOpenItemId'] = __('filament-accounting::errors.assignment_target_required');
            } elseif ((int) $candidate['remaining_minor'] === 0) {
                $errors['selectedOpenItemId'] = __('filament-accounting::errors.invalid_allocation_target');
            } elseif (abs((int) $line->amount_minor) > abs((int) $candidate['remaining_minor'])) {
                $errors['selectedOpenItemId'] = __('filament-accounting::errors.settlement_exceeds_remaining');
            }

            return $errors;
        }

        if ($this->assignmentType === 'posting_rule') {
            $valid = collect($this->query()->postingRuleCandidates($line))
                ->contains(fn (array $candidate): bool => $candidate['id'] === $this->selectedPostingRuleVersionId);
            if (! $valid) {
                $errors['selectedPostingRuleVersionId'] = __('filament-accounting::errors.assignment_target_required');
            }

            return $errors;
        }

        if ($this->assignmentType === 'ledger_account') {
            $valid = collect($this->query()->ledgerAccountCandidates($line))
                ->contains(fn (array $candidate): bool => $candidate['id'] === $this->selectedLedgerAccountId);
            if (! $valid) {
                $errors['selectedLedgerAccountId'] = __('filament-accounting::errors.assignment_target_required');
            }

            return $errors;
        }

        $errors['assignmentType'] = __('filament-accounting::errors.invalid_allocation_purpose');

        return $errors;
    }

    /** @return array<string, string> */
    private function splitValidationErrors(BankStatementLine $line): array
    {
        $errors = [];
        $sum = 0;
        $openItemTargets = [];

        if (count($this->allocations) < 2) {
            $errors['allocations'] = __('filament-accounting::errors.split_requires_multiple_allocations');
        }

        foreach ($this->allocations as $index => $allocation) {
            $type = (string) ($allocation['type'] ?? '');
            $amount = $this->minorFromInput($allocation['amount'] ?? null, $line->currency);

            if ($amount === null) {
                $errors["allocations.{$index}.amount"] = __('filament-accounting::errors.invalid_allocation_amount');

                continue;
            }

            if ($amount === 0) {
                $errors["allocations.{$index}.amount"] = __('filament-accounting::errors.zero_allocation');
            } elseif (($line->amount_minor > 0 && $amount < 0) || ($line->amount_minor < 0 && $amount > 0)) {
                $errors["allocations.{$index}.amount"] = __('filament-accounting::errors.allocation_sign_mismatch');
            }

            $sum += $amount;
            $targetId = is_numeric($allocation['target_id'] ?? null) ? (int) $allocation['target_id'] : null;

            if (in_array($type, ['sales_invoice', 'purchase_invoice'], true)) {
                $candidate = $this->query()->openItemCandidate($line, $targetId);
                $expectedKind = $type === 'sales_invoice' ? OpenItemKind::Receivable : OpenItemKind::Payable;
                if (! is_array($candidate) || ($candidate['kind'] ?? null) !== $expectedKind->value) {
                    $errors["allocations.{$index}.target_id"] = __('filament-accounting::errors.assignment_target_required');
                } elseif (abs($amount) > abs((int) $candidate['remaining_minor'])) {
                    $errors["allocations.{$index}.amount"] = __('filament-accounting::errors.settlement_exceeds_remaining');
                } elseif (in_array($targetId, $openItemTargets, true)) {
                    $errors["allocations.{$index}.target_id"] = __('filament-accounting::errors.duplicate_open_item_allocation');
                } else {
                    $openItemTargets[] = $targetId;
                }
            } elseif ($type === 'posting_rule') {
                $valid = collect($this->query()->postingRuleCandidates($line))
                    ->contains(fn (array $candidate): bool => $candidate['id'] === $targetId);
                if (! $valid) {
                    $errors["allocations.{$index}.target_id"] = __('filament-accounting::errors.assignment_target_required');
                }
            } elseif ($type === 'ledger_account') {
                $valid = collect($this->query()->ledgerAccountCandidates($line))
                    ->contains(fn (array $candidate): bool => $candidate['id'] === $targetId);
                if (! $valid) {
                    $errors["allocations.{$index}.target_id"] = __('filament-accounting::errors.assignment_target_required');
                }
            } else {
                $errors["allocations.{$index}.type"] = __('filament-accounting::errors.invalid_allocation_purpose');
            }
        }

        if ($sum !== (int) $line->amount_minor) {
            $errors['allocations.total'] = __('filament-accounting::errors.reconciliation_imbalance');
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function directAssignment(BankStatementLine $line): array
    {
        if ($this->assignmentType === 'posting_rule') {
            return [
                'purpose' => SplitPurpose::PostingRule->value,
                'posting_rule_version_id' => $this->selectedPostingRuleVersionId,
                'reason' => $this->allocationReason,
                'selection_source' => 'manual',
            ];
        }

        if ($this->assignmentType === 'ledger_account') {
            return [
                'purpose' => SplitPurpose::LedgerAccount->value,
                'ledger_account_id' => $this->selectedLedgerAccountId,
                'reason' => $this->allocationReason,
                'selection_source' => 'manual',
            ];
        }

        $candidate = $this->query()->openItemCandidate($line, $this->selectedOpenItemId);

        return [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $this->selectedOpenItemId,
            'reason' => $this->allocationReason,
            'selection_source' => (($candidate['score'] ?? 0) > 0) ? 'suggestion_confirmed' : 'manual',
            'suggestion_score' => (int) ($candidate['score'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function splitAllocations(BankStatementLine $line): array
    {
        return array_map(function (array $allocation) use ($line): array {
            $type = (string) ($allocation['type'] ?? '');
            $isInvoice = in_array($type, ['sales_invoice', 'purchase_invoice'], true);
            $isLedgerAccount = $type === 'ledger_account';

            return [
                'purpose' => $isInvoice
                    ? SplitPurpose::SettleOpenItem->value
                    : ($isLedgerAccount ? SplitPurpose::LedgerAccount->value : SplitPurpose::PostingRule->value),
                'amount_minor' => $this->minorFromInput($allocation['amount'] ?? null, $line->currency),
                'open_item_id' => $isInvoice ? ($allocation['target_id'] ?? null) : null,
                'posting_rule_version_id' => $type === 'posting_rule' ? ($allocation['target_id'] ?? null) : null,
                'ledger_account_id' => $isLedgerAccount ? ($allocation['target_id'] ?? null) : null,
                'reason' => $allocation['reason'] ?? null,
                'selection_source' => 'manual',
            ];
        }, $this->allocations);
    }

    private function allocatedMinor(BankStatementLine $line): int
    {
        $sum = 0;
        foreach ($this->allocations as $allocation) {
            $amount = $this->minorFromInput($allocation['amount'] ?? null, $line->currency);
            if ($amount !== null) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    private function remainingMinor(BankStatementLine $line): int
    {
        return (int) $line->amount_minor - $this->allocatedMinor($line);
    }

    private function minorFromInput(mixed $amount, string $currency): ?int
    {
        try {
            $value = trim((string) $amount);
            if ($value === '') {
                return null;
            }
            if (str_contains($value, ',') && ! str_contains($value, '.')) {
                $value = str_replace(',', '.', $value);
            }

            return ExactMoney::ofString($value, $currency)->minorAmount;
        } catch (InvalidMoneyException) {
            return null;
        }
    }

    private function payloadValue(BankStatementLine $line, array $keys): ?string
    {
        $payload = $line->source_payload;
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return null;
    }
}
