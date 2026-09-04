<?php

namespace FilamentAccounting\Reconciliation;

use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationLearningRule;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Support\Str;

final class ReconciliationAssistantQuery
{
    public function __construct(
        private readonly LegalEntityScope $scope,
        private readonly SuggestReconciliationMatches $matcher,
    ) {}

    public function statementLine(string $uuid): ?BankStatementLine
    {
        $entity = $this->scope->require();

        return BankStatementLine::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('uuid', $uuid)
            ->whereHas('bankAccount', fn ($query) => $query->where('is_active', true))
            ->with([
                'bankAccount',
                'reconciliations.journalEntry',
                'reconciliations.splits.openItem.document.party',
                'reconciliations.splits.postingRuleVersion.postingRule',
                'reconciliations.splits.ledgerAccount',
            ])
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoiceCandidates(
        BankStatementLine $line,
        OpenItemKind $kind,
        string $search = '',
        bool $onlyOpen = true,
        bool $amountNear = false,
    ): array {
        $suggestions = [];
        foreach ($this->matcher->handle($line) as $suggestion) {
            $suggestions[$suggestion->targetId] = $suggestion;
        }
        $needle = Str::lower(trim($search));
        $tolerance = max(100, (int) round(abs((int) $line->amount_minor) * 0.05));

        return OpenItem::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('kind', $kind)
            ->where('currency', strtoupper($line->currency))
            ->where('is_reversed', false)
            ->with(['document.party', 'party', 'settlements'])
            ->get()
            ->map(function (OpenItem $item) use ($suggestions): array {
                $settled = (int) $item->settlements
                    ->where('is_reversed', false)
                    ->sum('amount_minor');
                $remaining = (int) $item->original_minor - $settled;
                $suggestion = $suggestions[(int) $item->getKey()] ?? null;
                $document = $item->document;

                return [
                    'id' => (int) $item->getKey(),
                    'uuid' => (string) $item->uuid,
                    'number' => $document->number,
                    'supplier_invoice_number' => $document->supplier_invoice_number,
                    'party' => $item->party?->displayLabel(),
                    'issue_date' => $document->issue_date?->toDateString(),
                    'receipt_date' => $document->receipt_date?->toDateString(),
                    'due_date' => $item->due_on?->toDateString(),
                    'gross_minor' => (int) ($document->gross_minor ?? $item->original_minor),
                    'settled_minor' => $settled,
                    'remaining_minor' => $remaining,
                    'currency' => (string) $item->currency,
                    'payment_status' => $this->paymentStatus((int) $item->original_minor, $remaining)->value,
                    'score' => $suggestion->score ?? 0,
                    'confidence' => $suggestion?->confidence() ?? 'none',
                    'reasons' => $suggestion->reasons ?? [],
                    'ambiguous' => $suggestion->ambiguous ?? false,
                ];
            })
            ->filter(function (array $candidate) use ($needle, $onlyOpen, $amountNear, $line, $tolerance): bool {
                if ($onlyOpen && ((int) $candidate['remaining_minor'] === 0)) {
                    return false;
                }

                if ($amountNear && (abs(abs((int) $candidate['remaining_minor']) - abs((int) $line->amount_minor)) > $tolerance)) {
                    return false;
                }

                if ($needle === '') {
                    return true;
                }

                $haystack = Str::lower(implode(' ', array_filter([
                    $candidate['number'],
                    $candidate['supplier_invoice_number'],
                    $candidate['party'],
                ])));

                return str_contains($haystack, $needle);
            })
            ->sort(function (array $left, array $right): int {
                $scoreOrder = ((int) $right['score']) <=> ((int) $left['score']);

                return $scoreOrder !== 0
                    ? $scoreOrder
                    : ((string) ($left['due_date'] ?? '9999-12-31')) <=> ((string) ($right['due_date'] ?? '9999-12-31'));
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function openItemCandidate(BankStatementLine $line, ?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        foreach ([OpenItemKind::Receivable, OpenItemKind::Payable] as $kind) {
            $candidate = collect($this->invoiceCandidates($line, $kind, onlyOpen: false))
                ->firstWhere('id', $id);

            if (is_array($candidate)) {
                $candidate['kind'] = $kind->value;

                return $candidate;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function postingRuleCandidates(BankStatementLine $line, string $search = ''): array
    {
        $date = ($line->booking_date ?? now())->toDateString();
        $direction = $line->isIncoming() ? 'incoming' : 'outgoing';
        $needle = Str::lower(trim($search));
        $taxRates = [];
        $learnedRuleIds = ReconciliationLearningRule::matching($line, 'posting_rule')
            ->pluck('target_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return PostingRule::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(function (PostingRule $rule) use ($date, $direction, $line, $learnedRuleIds, &$taxRates): ?array {
                $version = $rule->versionOn($date);
                if (! $version instanceof PostingRuleVersion
                    || (filled($version->direction) && $version->direction !== $direction)) {
                    return null;
                }

                $taxCode = filled($version->tax_code) ? (string) $version->tax_code : null;
                $taxRuleVersion = null;
                if ($taxCode !== null && ! array_key_exists($taxCode, $taxRates)) {
                    $taxRuleVersion = TaxCode::query()
                        ->where('legal_entity_id', $line->legal_entity_id)
                        ->where('code', $taxCode)
                        ->first()?->versionOn($date);
                    $taxRates[$taxCode] = $taxRuleVersion;
                } elseif ($taxCode !== null) {
                    $taxRuleVersion = $taxRates[$taxCode] ?? null;
                }

                return [
                    'id' => (int) $version->getKey(),
                    'code' => (string) $rule->code,
                    'label' => (string) $rule->label,
                    'explanation' => filled($rule->explanation) && trim((string) $rule->explanation) !== trim((string) $rule->label)
                        ? (string) $rule->explanation
                        : null,
                    'profile' => (string) ($rule->compliance_profile_key ?: ''),
                    'tax_code' => $taxCode,
                    'tax_rule_version_id' => $taxRuleVersion?->getKey(),
                    'tax_rate_bp' => $taxRuleVersion?->rate_bp,
                    'direction' => $version->direction,
                    'score' => in_array((int) $rule->getKey(), $learnedRuleIds, true) ? 35 : 0,
                    'reasons' => in_array((int) $rule->getKey(), $learnedRuleIds, true) ? ['learned_rule'] : [],
                ];
            })
            ->filter()
            ->filter(function (array $candidate) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                return str_contains(Str::lower(implode(' ', [
                    $candidate['code'],
                    $candidate['label'],
                    $candidate['explanation'],
                    $candidate['profile'],
                    $candidate['tax_code'],
                ])), $needle);
            })
            ->sortByDesc(fn (array $candidate): int => (int) $candidate['score'])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, code: string, name: string, type: string}> */
    public function ledgerAccountCandidates(BankStatementLine $line): array
    {
        return LedgerAccount::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->where('type', '!=', AccountType::Asset->value)
            ->whereKeyNot($line->bankAccount->ledger_account_id)
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $account): array => [
                'id' => (int) $account->getKey(),
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'type' => $account->type->value,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function categoryCandidates(BankStatementLine $line, string $search = ''): array
    {
        $taxRates = $this->taxRateCandidates($line);
        $standardTax = collect($taxRates)->firstWhere('code', 'DE-19');
        $needle = Str::lower(trim($search));

        $rules = collect($this->postingRuleCandidates($line, $search))->map(fn (array $rule): array => [
            ...$rule,
            'key' => 'rule:'.$rule['id'],
            'kind' => 'posting_rule',
            'account_code' => null,
            'type' => null,
            'name' => $rule['label'],
            'allows_tax' => $rule['tax_rule_version_id'] !== null,
            'default_tax_rule_version_id' => $rule['tax_rule_version_id'],
        ]);

        $accounts = collect($this->ledgerAccountCandidates($line))
            ->map(function (array $account) use ($standardTax): array {
                $allowsTax = in_array($account['type'], [AccountType::Expense->value, AccountType::Revenue->value], true);

                return [
                    'key' => 'account:'.$account['id'],
                    'kind' => 'ledger_account',
                    'id' => $account['id'],
                    'code' => null,
                    'account_code' => $account['code'],
                    'type' => $account['type'],
                    'name' => $account['name'],
                    'label' => $account['name'],
                    'explanation' => null,
                    'score' => 0,
                    'reasons' => [],
                    'allows_tax' => $allowsTax,
                    'default_tax_rule_version_id' => $allowsTax ? ($standardTax['id'] ?? null) : null,
                    'tax_rule_version_id' => $allowsTax ? ($standardTax['id'] ?? null) : null,
                    'tax_rate_bp' => $allowsTax ? ($standardTax['rate_bp'] ?? null) : null,
                ];
            })
            ->filter(function (array $candidate) use ($needle): bool {
                return $needle === '' || str_contains(Str::lower($candidate['account_code'].' '.$candidate['name']), $needle);
            });

        return $rules
            ->concat($accounts)
            ->sortByDesc(fn (array $candidate): int => (int) $candidate['score'])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, code: string, name: string, rate_bp: int}> */
    public function taxRateCandidates(BankStatementLine $line): array
    {
        $date = ($line->booking_date ?? now())->toDateString();
        $direction = $line->isIncoming() ? 'incoming' : 'outgoing';

        return TaxCode::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->where(function ($query) use ($direction): void {
                $query->whereNull('direction')->orWhere('direction', 'both')->orWhere('direction', $direction);
            })
            ->orderBy('name')
            ->get()
            ->map(function (TaxCode $taxCode) use ($date): ?array {
                $version = $taxCode->versionOn($date);

                return $version ? [
                    'id' => (int) $version->getKey(),
                    'code' => (string) $taxCode->code,
                    'name' => (string) $taxCode->name,
                    'rate_bp' => (int) $version->rate_bp,
                ] : null;
            })
            ->filter()
            ->sortByDesc('rate_bp')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function postedAllocations(Reconciliation $reconciliation): array
    {
        return $reconciliation->splits->map(function (ReconciliationSplit $split): array {
            return [
                'amount' => MoneyFormatter::format((int) $split->amount_minor, $split->currency),
                'purpose' => __('filament-accounting::fields.assignment_types.'.$this->assistantType($split)),
                'target' => $this->allocationTargetLabel($split),
                'url' => $this->allocationTargetUrl($split),
                'reason' => $split->reason,
            ];
        })->all();
    }

    public function sourceUrl(BankStatementLine $line): string
    {
        return BankStatementLineResource::getUrl('view', ['record' => $line]);
    }

    private function paymentStatus(int $original, int $remaining): PaymentStatus
    {
        if ($remaining === $original) {
            return PaymentStatus::Unpaid;
        }

        if ($remaining === 0) {
            return PaymentStatus::Paid;
        }

        if (($original > 0 && $remaining < 0) || ($original < 0 && $remaining > 0)) {
            return PaymentStatus::Overpaid;
        }

        return PaymentStatus::PartiallyPaid;
    }

    private function assistantType(ReconciliationSplit $split): string
    {
        if ($split->openItem?->kind === OpenItemKind::Receivable) {
            return 'sales_invoice';
        }

        if ($split->openItem?->kind === OpenItemKind::Payable) {
            return 'purchase_invoice';
        }

        return 'posting_rule';
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

        return __('filament-accounting::fields.assignment_types.posting_rule');
    }

    private function allocationTargetUrl(ReconciliationSplit $split): ?string
    {
        $document = $split->openItem?->document;
        if (! $document) {
            return null;
        }

        try {
            return match ($document->type) {
                DocumentType::SalesInvoice => SalesInvoiceResource::getUrl('view', ['record' => $document]),
                DocumentType::PurchaseInvoice => PurchaseInvoiceResource::getUrl('view', ['record' => $document]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
