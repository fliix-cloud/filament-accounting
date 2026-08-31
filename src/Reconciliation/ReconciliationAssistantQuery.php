<?php

namespace FilamentAccounting\Reconciliation;

use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Support\BankSourceLinkRegistry;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Support\Str;

final class ReconciliationAssistantQuery
{
    public function __construct(
        private readonly LegalEntityScope $scope,
        private readonly SuggestReconciliationMatches $matcher,
        private readonly BankSourceLinkRegistry $sourceLinks,
    ) {}

    public function statementLine(string $uuid): ?BankStatementLine
    {
        $entity = $this->scope->require();

        return BankStatementLine::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('uuid', $uuid)
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

        return PostingRule::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(function (PostingRule $rule) use ($date, $direction, $line, &$taxRates): ?array {
                $version = $rule->versionOn($date);
                if (! $version instanceof PostingRuleVersion
                    || (filled($version->direction) && $version->direction !== $direction)) {
                    return null;
                }

                $taxCode = filled($version->tax_code) ? (string) $version->tax_code : null;
                if ($taxCode !== null && ! array_key_exists($taxCode, $taxRates)) {
                    $taxRates[$taxCode] = TaxCode::query()
                        ->where('legal_entity_id', $line->legal_entity_id)
                        ->where('code', $taxCode)
                        ->first()?->versionOn($date)?->rate_bp;
                }

                return [
                    'id' => (int) $version->getKey(),
                    'code' => (string) $rule->code,
                    'label' => (string) $rule->label,
                    'explanation' => (string) ($rule->explanation ?: $rule->label),
                    'profile' => (string) ($rule->compliance_profile_key ?: ''),
                    'tax_code' => $taxCode,
                    'tax_rate_bp' => $taxCode !== null ? ($taxRates[$taxCode] ?? null) : null,
                    'direction' => $version->direction,
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

    public function sourceUrl(BankStatementLine $line): ?string
    {
        return $this->sourceLinks->url($line);
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
