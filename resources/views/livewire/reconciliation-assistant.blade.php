<div @class(['fac-assistant', 'fac-assistant-modal' => $this->context === 'modal'])>
    <style>
        .fac-assistant { --fac-border: rgb(var(--gray-200), 1); display: grid; gap: 1rem; color: rgb(var(--gray-950)); }
        .dark .fac-assistant { --fac-border: rgb(var(--gray-700), 1); color: rgb(var(--gray-50)); }
        .fac-assistant-modal { padding: 0 .125rem .25rem; }
        .fac-card { border: 1px solid var(--fac-border); border-radius: .65rem; background: rgb(var(--gray-50)); padding: .85rem; }
        .dark .fac-card { background: rgb(var(--gray-900)); }
        .fac-transaction-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem 1rem; }
        .fac-detail { min-width: 0; }
        .fac-detail-wide { grid-column: span 2; }
        .fac-label { display: block; color: rgb(var(--gray-500)); font-size: .75rem; font-weight: 600; letter-spacing: .025em; text-transform: uppercase; }
        .fac-value { display: block; margin-top: .2rem; overflow-wrap: anywhere; }
        .fac-amount { align-items: center; display: inline-flex; font-size: 1.25rem; font-variant-numeric: tabular-nums; font-weight: 700; gap: .35rem; }
        .fac-amount-incoming { color: rgb(var(--success-700)); }
        .dark .fac-amount-incoming { color: rgb(var(--success-400)); }
        .fac-amount-outgoing { color: #2563eb; }
        .dark .fac-amount-outgoing { color: #60a5fa; }
        .fac-transaction-summary { align-items: center; display: flex; gap: 1rem; justify-content: space-between; }
        .fac-summary-line { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .3rem; }
        .fac-summary-counterparty { font-weight: 600; }
        .fac-details { border-top: 1px solid var(--fac-border); margin-top: .75rem; padding-top: .65rem; }
        .fac-details > summary, .fac-row-details > summary { color: rgb(var(--primary-600)); cursor: pointer; font-size: .75rem; font-weight: 600; list-style-position: inside; }
        .dark .fac-details > summary, .dark .fac-row-details > summary { color: rgb(var(--primary-400)); }
        .fac-type-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
        .fac-type { align-items: center; background: #fff; border: 1px solid #d1d5db; border-radius: .5rem; display: flex; gap: .55rem; min-height: 4.25rem; padding: .6rem .65rem; text-align: left; transition: background .15s, border-color .15s; }
        .fac-type:hover { border-color: rgb(var(--primary-400)); }
        .fac-type[aria-selected="true"] { background: #eff6ff; border-color: #2563eb; border-width: 2px; color: #1e3a8a; }
        .dark .fac-type { background: #1f2937; border-color: #4b5563; }
        .dark .fac-type[aria-selected="true"] { background: #172554; border-color: #60a5fa; color: #dbeafe; }
        .fac-type-icon { flex: 0 0 auto; height: 1.5rem; width: 1.5rem; }
        .fac-type-copy { display: grid; gap: .25rem; }
        .fac-type-copy small, .fac-muted, .fac-reasons, .fac-date-line { color: rgb(var(--gray-500)); font-size: .75rem; }
        .fac-type[aria-selected="true"] .fac-type-copy small { color: #1d4ed8; }
        .dark .fac-type[aria-selected="true"] .fac-type-copy small { color: #bfdbfe; }
        .fac-date-line, .fac-reasons { display: block; }
        .fac-toolbar { align-items: end; display: flex; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
        .fac-toolbar-search { flex: 1 1 18rem; }
        .fac-check { align-items: center; display: inline-flex; gap: .4rem; min-height: 2.5rem; }
        .fac-table-wrap { overflow-x: auto; }
        .fac-table { border-collapse: collapse; min-width: 48rem; width: 100%; }
        .fac-table th { color: rgb(var(--gray-500)); font-size: .7rem; font-weight: 600; letter-spacing: .025em; padding: .55rem; text-align: left; text-transform: uppercase; }
        .fac-table td { border-top: 1px solid var(--fac-border); font-size: .82rem; padding: .55rem .45rem; vertical-align: top; }
        .fac-table .fac-number { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .fac-open-amount { font-weight: 700; }
        .fac-selected-row { background: rgb(var(--primary-50)); }
        .dark .fac-selected-row { background: rgb(var(--primary-950) / .35); }
        .fac-badge { background: rgb(var(--gray-100)); border-radius: 999px; display: inline-flex; font-size: .7rem; font-weight: 600; padding: .18rem .45rem; white-space: nowrap; }
        .dark .fac-badge { background: rgb(var(--gray-800)); }
        .fac-confidence-high { background: rgb(var(--success-100)); color: rgb(var(--success-800)); }
        .fac-confidence-medium { background: rgb(var(--warning-100)); color: rgb(var(--warning-800)); }
        .fac-confidence-low { background: rgb(var(--gray-100)); color: rgb(var(--gray-700)); }
        .dark .fac-confidence-high { background: rgb(var(--success-950)); color: rgb(var(--success-300)); }
        .dark .fac-confidence-medium { background: rgb(var(--warning-950)); color: rgb(var(--warning-300)); }
        .fac-row-details { margin-top: .3rem; }
        .fac-detail-grid { display: grid; gap: .35rem .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: .4rem; }
        .fac-detail-grid > span { color: rgb(var(--gray-600)); font-size: .72rem; }
        .dark .fac-detail-grid > span { color: rgb(var(--gray-400)); }
        .fac-alert { align-items: flex-start; border: 1px solid rgb(var(--warning-300)); border-radius: .6rem; display: flex; gap: .5rem; margin-bottom: .8rem; padding: .65rem .75rem; }
        .fac-alert-danger { border-color: rgb(var(--danger-400)); color: rgb(var(--danger-700)); }
        .dark .fac-alert-danger { color: rgb(var(--danger-300)); }
        .fac-error { color: rgb(var(--danger-600)); display: block; font-size: .75rem; margin-top: .25rem; }
        .dark .fac-error { color: rgb(var(--danger-400)); }
        .fac-empty { color: rgb(var(--gray-500)); padding: 1.5rem; text-align: center; }
        .fac-category-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        .fac-category { border: 1px solid var(--fac-border); border-radius: .65rem; display: grid; gap: .5rem; padding: .8rem; }
        .fac-category-selected { border-color: rgb(var(--primary-500)); box-shadow: 0 0 0 2px rgb(var(--primary-500) / .15); }
        .fac-category-meta { display: flex; flex-wrap: wrap; gap: .35rem; }
        .fac-split-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .fac-split-list { display: grid; gap: .75rem; }
        .fac-split-row { border: 1px solid var(--fac-border); border-radius: .65rem; padding: .8rem; }
        .fac-split-grid { align-items: end; display: grid; grid-template-columns: 1fr 1.35fr .75fr 1fr auto; gap: .75rem; }
        .fac-field { display: grid; gap: .3rem; }
        .fac-footer { align-items: center; background: rgb(var(--gray-50)); border-top: 1px solid var(--fac-border); display: flex; flex-wrap: wrap; gap: .65rem; justify-content: flex-end; margin: 0 -.25rem -.25rem; padding: .8rem .25rem .25rem; }
        .dark .fac-footer { background: rgb(var(--gray-900) / .94); }
        @media (max-width: 75rem) { .fac-transaction-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fac-type-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fac-category-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fac-split-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 42rem) { .fac-transaction-grid, .fac-type-grid, .fac-category-grid, .fac-split-summary, .fac-split-grid { grid-template-columns: 1fr; } .fac-detail-wide { grid-column: auto; } .fac-transaction-summary { align-items: flex-start; flex-direction: column; gap: .5rem; } .fac-amount { font-size: 1.05rem; } }
    </style>

    @if (! $statementLine)
        <div class="fac-card">
            <p>{{ __('filament-accounting::fields.select_line') }}</p>
        </div>
    @else
        <section class="fac-card" aria-labelledby="fac-transaction-heading">
            <div class="fac-transaction-summary">
                <div>
                    <h2 id="fac-transaction-heading" style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.transaction_details') }}</h2>
                    <div class="fac-summary-line">
                        <span class="fac-summary-counterparty">{{ $statementLine->counterparty_name ?: __('filament-accounting::fields.not_available') }}</span>
                        <span class="fac-badge">{{ __('filament-accounting::statuses.reconciliation.'.$statementLine->derivedBadge()->value) }}</span>
                    </div>
                    <span class="fac-muted">{{ $statementLine->booking_date?->toDateString() ?: __('filament-accounting::fields.not_available') }} · {{ $statementLine->bankAccount->display_name }}</span>
                </div>
                <span @class(['fac-amount', 'fac-amount-incoming' => $statementLine->isIncoming(), 'fac-amount-outgoing' => ! $statementLine->isIncoming()])>
                    <x-filament::icon :icon="$statementLine->isIncoming() ? 'heroicon-o-arrow-down-left' : 'heroicon-o-arrow-up-right'" style="height: 1.25rem; width: 1.25rem;" />
                    <span>{{ $statementLine->isIncoming() ? __('filament-accounting::fields.money_in') : __('filament-accounting::fields.money_out') }}</span>
                    <span>{{ \FilamentAccounting\Support\MoneyFormatter::format($statementLine->amount_minor, $statementLine->currency) }}</span>
                </span>
            </div>

            <details class="fac-details">
                <summary>{{ __('filament-accounting::fields.show_transaction_details') }}</summary>
                <div class="fac-transaction-grid" style="margin-top: .7rem;">
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.counterparty') }}</span><span class="fac-value">{{ $statementLine->counterparty_name ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.booking_date') }}</span><span class="fac-value">{{ $statementLine->booking_date?->toDateString() ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.value_date') }}</span><span class="fac-value">{{ $statementLine->value_date?->toDateString() ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.booking_type') }}</span><span class="fac-value">{{ $bookingType ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.counterparty_iban') }}</span><span class="fac-value">{{ $statementLine->counterparty_iban ?: $statementLine->counterparty_account ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.counterparty_bic') }}</span><span class="fac-value">{{ $counterpartyBic ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.bank_account') }}</span><span class="fac-value">{{ $statementLine->bankAccount->display_name }} · {{ $statementLine->bankAccount->iban }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.reference') }}</span><span class="fac-value">{{ $statementLine->payment_reference ?: $statementLine->end_to_end_id ?: __('filament-accounting::fields.not_available') }}</span></div>
                <div class="fac-detail fac-detail-wide"><span class="fac-label">{{ __('filament-accounting::fields.purpose') }}</span><span class="fac-value">{{ $statementLine->purpose ?: __('filament-accounting::fields.no_purpose') }}</span></div>
                <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.source_status') }}</span><span class="fac-value">{{ __('filament-accounting::statuses.statement.'.$statementLine->source_status->value) }}</span></div>
                @if ($sourceUrl)
                    <div class="fac-detail"><span class="fac-label">{{ __('filament-accounting::fields.source') }}</span><a href="{{ $sourceUrl }}" target="_blank" rel="noopener" class="fi-link fac-value">{{ __('filament-accounting::actions.open_source') }}</a></div>
                @endif
                </div>
            </details>
        </section>

        @if ($postedReconciliation)
            <section class="fac-card">
                <h2 style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.posted_assignment') }}</h2>
                <p class="fac-muted" style="margin-top: .25rem;">{{ ($postedReconciliation->match_meta['mode'] ?? null) === 'split' ? __('filament-accounting::fields.split_assignment_summary') : __('filament-accounting::fields.direct_assignment_summary') }}</p>
                <div style="display: grid; gap: .65rem; margin-top: .8rem;">
                    @foreach ($postedAllocations as $allocation)
                        <div style="align-items: start; border-top: 1px solid var(--fac-border); display: flex; gap: 1rem; justify-content: space-between; padding-top: .65rem;">
                            <div>
                                <strong>
                                    @if ($allocation['url'])<a href="{{ $allocation['url'] }}" class="fi-link">{{ $allocation['target'] }}</a>@else{{ $allocation['target'] }}@endif
                                </strong>
                                <span class="fac-reasons">{{ $allocation['purpose'] }}@if ($allocation['reason']) · {{ $allocation['reason'] }}@endif</span>
                            </div>
                            <strong style="font-variant-numeric: tabular-nums;">{{ $allocation['amount'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            @if ($statementLine->source_status->value !== 'booked')
                <section class="fac-card">
                    <div class="fac-alert">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" style="height: 1.25rem; width: 1.25rem;" />
                        <div><strong>{{ __('filament-accounting::fields.pending_transaction') }}</strong><p>{{ __('filament-accounting::fields.pending_transaction_help') }}</p></div>
                    </div>
                    <label class="fac-field"><span>{{ __('filament-accounting::fields.exception_reason') }}</span><x-filament::input.wrapper><x-filament::input type="text" wire:model.live="exceptionReason" /></x-filament::input.wrapper></label>
                    @if ($validationErrors['exceptionReason'] ?? null)<span class="fac-error">{{ $validationErrors['exceptionReason'] }}</span>@endif
                </section>
            @endif

            <section aria-labelledby="fac-assignment-type-heading">
                <h2 id="fac-assignment-type-heading" style="font-size: 1rem; font-weight: 700; margin-bottom: .65rem;">{{ __('filament-accounting::fields.choose_assignment_type') }}</h2>
                <div class="fac-type-grid" role="tablist" aria-label="{{ __('filament-accounting::fields.choose_assignment_type') }}">
                    @foreach ([
                        'sales_invoice' => 'heroicon-o-document-currency-euro',
                        'purchase_invoice' => 'heroicon-o-receipt-percent',
                        'posting_rule' => 'heroicon-o-scale',
                        'ledger_account' => 'heroicon-o-book-open',
                        'split' => 'heroicon-o-view-columns',
                    ] as $type => $icon)
                        <button type="button" class="fac-type" role="tab" aria-selected="{{ $this->assignmentType === $type ? 'true' : 'false' }}" wire:click="selectAssignmentType('{{ $type }}')">
                            <x-filament::icon :icon="$icon" class="fac-type-icon" />
                            <span class="fac-type-copy"><strong>{{ __('filament-accounting::fields.assignment_types.'.$type) }}</strong><small>{{ __('filament-accounting::fields.assignment_type_help.'.$type) }}</small></span>
                        </button>
                    @endforeach
                </div>
                @if ($validationErrors['assignmentType'] ?? null)<span class="fac-error">{{ $validationErrors['assignmentType'] }}</span>@endif
            </section>

            @if (in_array($this->assignmentType, ['sales_invoice', 'purchase_invoice'], true))
                <section class="fac-card">
                    <h2 style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.assignment_types.'.$this->assignmentType) }}</h2>
                    <p class="fac-muted">{{ __('filament-accounting::fields.invoice_selection_help') }}</p>
                    @if (($statementLine->isIncoming() && $this->assignmentType === 'purchase_invoice') || (! $statementLine->isIncoming() && $this->assignmentType === 'sales_invoice'))
                        <div class="fac-alert" style="margin-top: .8rem;"><x-filament::icon icon="heroicon-o-information-circle" style="height: 1.25rem; width: 1.25rem;" /><span>{{ __('filament-accounting::errors.unsupported_invoice_direction') }}</span></div>
                    @endif
                    <div class="fac-toolbar" style="margin-top: .8rem;">
                        <label class="fac-field fac-toolbar-search"><span>{{ __('filament-accounting::fields.search_invoices') }}</span><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="invoiceSearch" /></x-filament::input.wrapper></label>
                        <label class="fac-check"><input type="checkbox" wire:model.live="onlyOpen" /> <span>{{ __('filament-accounting::fields.only_open') }}</span></label>
                        <label class="fac-check"><input type="checkbox" wire:model.live="amountNear" /> <span>{{ __('filament-accounting::fields.amount_near') }}</span></label>
                    </div>
                    @include('filament-accounting::livewire.partials.invoice-candidates', [
                        'candidates' => $this->assignmentType === 'sales_invoice' ? $salesInvoices : $purchaseInvoices,
                        'candidateType' => $this->assignmentType,
                    ])
                    @if ($validationErrors['selectedOpenItemId'] ?? null)<span class="fac-error">{{ $validationErrors['selectedOpenItemId'] }}</span>@endif
                    @if (is_array($selectedOpenItem) && abs($statementLine->amount_minor) < abs($selectedOpenItem['remaining_minor']))
                        <div class="fac-alert" style="margin-top: .8rem;"><x-filament::icon icon="heroicon-o-information-circle" style="height: 1.25rem; width: 1.25rem;" /><span>{{ __('filament-accounting::fields.partial_payment_notice', ['payment' => \FilamentAccounting\Support\MoneyFormatter::format($statementLine->amount_minor, $statementLine->currency), 'open' => \FilamentAccounting\Support\MoneyFormatter::format($selectedOpenItem['remaining_minor'], $selectedOpenItem['currency'])]) }}</span></div>
                    @endif
                </section>
            @elseif ($this->assignmentType === 'posting_rule')
                <section class="fac-card">
                    <h2 style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.assignment_types.posting_rule') }}</h2>
                    <p class="fac-muted">{{ __('filament-accounting::fields.posting_rule_selection_help') }}</p>
                    <label class="fac-field" style="margin: .8rem 0;"><span>{{ __('filament-accounting::fields.search_categories') }}</span><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="postingRuleSearch" /></x-filament::input.wrapper></label>
                    <div class="fac-category-grid">
                        @forelse ($postingRules as $rule)
                            <article @class(['fac-category', 'fac-category-selected' => $this->selectedPostingRuleVersionId === $rule['id']])>
                                <div><strong>{{ $rule['code'] }} · {{ $rule['label'] }}</strong><p class="fac-muted">{{ $rule['explanation'] }}</p></div>
                                <div class="fac-category-meta">
                                    @if ($rule['profile'])<span class="fac-badge">{{ $rule['profile'] }}</span>@endif
                                    @if ($rule['tax_code'])<span class="fac-badge">{{ $rule['tax_code'] }}@if ($rule['tax_rate_bp'] !== null) · {{ number_format($rule['tax_rate_bp'] / 100, 2) }} % @endif</span>@endif
                                </div>
                                <x-filament::button type="button" size="sm" :color="$this->selectedPostingRuleVersionId === $rule['id'] ? 'success' : 'gray'" wire:click="selectPostingRule({{ $rule['id'] }})">{{ $this->selectedPostingRuleVersionId === $rule['id'] ? __('filament-accounting::fields.selected') : __('filament-accounting::actions.select') }}</x-filament::button>
                            </article>
                        @empty
                            <p class="fac-empty">{{ __('filament-accounting::fields.no_posting_rules') }}</p>
                        @endforelse
                    </div>
                    @if ($validationErrors['selectedPostingRuleVersionId'] ?? null)<span class="fac-error">{{ $validationErrors['selectedPostingRuleVersionId'] }}</span>@endif
                </section>
            @elseif ($this->assignmentType === 'ledger_account')
                <section class="fac-card">
                    <h2 style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.assignment_types.ledger_account') }}</h2>
                    <p class="fac-muted">{{ __('filament-accounting::fields.ledger_account_selection_help') }}</p>
                    <div class="fac-category-grid" style="margin-top: .8rem;">
                        @forelse ($ledgerAccounts as $account)
                            <article @class(['fac-category', 'fac-category-selected' => $this->selectedLedgerAccountId === $account['id']])>
                                <div>
                                    <strong>{{ $account['code'] }} · {{ $account['name'] }}</strong>
                                    <p class="fac-muted">{{ $account['type'] }}</p>
                                </div>
                                <x-filament::button type="button" size="sm" :color="$this->selectedLedgerAccountId === $account['id'] ? 'success' : 'gray'" wire:click="selectLedgerAccount({{ $account['id'] }})">{{ $this->selectedLedgerAccountId === $account['id'] ? __('filament-accounting::fields.selected') : __('filament-accounting::actions.select') }}</x-filament::button>
                            </article>
                        @empty
                            <p class="fac-empty">{{ __('filament-accounting::fields.no_ledger_accounts') }}</p>
                        @endforelse
                    </div>
                    @if ($validationErrors['selectedLedgerAccountId'] ?? null)<span class="fac-error">{{ $validationErrors['selectedLedgerAccountId'] }}</span>@endif
                </section>
            @else
                <section class="fac-card">
                    <h2 style="font-size: 1rem; font-weight: 700;">{{ __('filament-accounting::fields.assignment_types.split') }}</h2>
                    <p class="fac-muted">{{ __('filament-accounting::fields.split_transaction_help') }}</p>
                    <div class="fac-split-summary" style="margin-top: .8rem;">
                        <div><span class="fac-label">{{ __('filament-accounting::fields.total_amount') }}</span><strong class="fac-value">{{ \FilamentAccounting\Support\MoneyFormatter::format($statementLine->amount_minor, $statementLine->currency) }}</strong></div>
                        <div><span class="fac-label">{{ __('filament-accounting::fields.allocated_amount') }}</span><strong class="fac-value">{{ \FilamentAccounting\Support\MoneyFormatter::format($allocatedMinor, $statementLine->currency) }}</strong></div>
                        <div><span class="fac-label">{{ __('filament-accounting::fields.remaining') }}</span><strong class="fac-value">{{ \FilamentAccounting\Support\MoneyFormatter::format($remainingMinor, $statementLine->currency) }}</strong></div>
                    </div>
                    @if (count($this->allocations) === 1)<div class="fac-alert"><x-filament::icon icon="heroicon-o-information-circle" style="height: 1.25rem; width: 1.25rem;" /><span>{{ __('filament-accounting::fields.single_split_hint') }}</span></div>@endif
                    @if ($validationErrors['allocations.total'] ?? null)<span class="fac-error">{{ $validationErrors['allocations.total'] }}</span>@endif
                    @if ($validationErrors['allocations'] ?? null)<span class="fac-error">{{ $validationErrors['allocations'] }}</span>@endif

                    <div class="fac-split-list" style="margin-top: .8rem;">
                        @foreach ($this->allocations as $index => $allocation)
                            @php
                                $type = $allocation['type'] ?? 'sales_invoice';
                                $targets = $type === 'sales_invoice' ? $salesInvoices : ($type === 'purchase_invoice' ? $purchaseInvoices : ($type === 'ledger_account' ? $ledgerAccounts : $postingRules));
                                $target = collect($targets)->firstWhere('id', (int) ($allocation['target_id'] ?? 0));
                            @endphp
                            <article class="fac-split-row" wire:key="reconciliation-allocation-{{ $index }}">
                                <div class="fac-split-grid">
                                    <label class="fac-field"><span>{{ __('filament-accounting::fields.assignment_type') }}</span><x-filament::input.wrapper><x-filament::input.select wire:change="changeAllocationType({{ $index }}, $event.target.value)">@foreach (['sales_invoice', 'purchase_invoice', 'posting_rule', 'ledger_account'] as $splitType)<option value="{{ $splitType }}" @selected($type === $splitType)>{{ __('filament-accounting::fields.assignment_types.'.$splitType) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@if ($validationErrors["allocations.{$index}.type"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.type"] }}</span>@endif</label>
                                    <label class="fac-field"><span>{{ __('filament-accounting::fields.target') }}</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="allocations.{{ $index }}.target_id"><option value="">{{ __('filament-accounting::fields.select_target') }}</option>@foreach ($targets as $candidate)<option value="{{ $candidate['id'] }}">@if ($type === 'posting_rule'){{ $candidate['code'] }} · {{ $candidate['label'] }}@elseif ($type === 'ledger_account'){{ $candidate['code'] }} · {{ $candidate['name'] }}@else{{ $candidate['number'] ?: $candidate['supplier_invoice_number'] }} · {{ $candidate['party'] }} · {{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['remaining_minor'], $candidate['currency']) }}@endif</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@if ($validationErrors["allocations.{$index}.target_id"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.target_id"] }}</span>@endif</label>
                                    <label class="fac-field"><span>{{ __('filament-accounting::fields.amount') }} ({{ $statementLine->currency }})</span><x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model.live.debounce.300ms="allocations.{{ $index }}.amount" /></x-filament::input.wrapper>@if ($validationErrors["allocations.{$index}.amount"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.amount"] }}</span>@endif</label>
                                    <label class="fac-field"><span>{{ __('filament-accounting::fields.reason_optional') }}</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="allocations.{{ $index }}.reason" /></x-filament::input.wrapper>@if (is_array($target) && isset($target['remaining_minor']))<span class="fac-muted">{{ __('filament-accounting::fields.open_amount') }}: {{ \FilamentAccounting\Support\MoneyFormatter::format($target['remaining_minor'], $target['currency']) }}</span>@endif</label>
                                    <div style="display: flex; gap: .35rem;"><x-filament::button type="button" size="sm" color="gray" wire:click="useRemaining({{ $index }})">{{ __('filament-accounting::actions.use_remaining') }}</x-filament::button><x-filament::icon-button type="button" color="danger" icon="heroicon-o-trash" label="{{ __('filament-accounting::actions.remove_allocation') }}" wire:click="removeAllocation({{ $index }})" /></div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <x-filament::button type="button" color="gray" icon="heroicon-o-plus" style="margin-top: .8rem;" wire:click="addAllocation">{{ __('filament-accounting::actions.add_allocation') }}</x-filament::button>
                </section>
            @endif

            @if ($this->assignmentType !== 'split')
                <section class="fac-card">
                    <label class="fac-field"><span>{{ __('filament-accounting::fields.reason_optional') }}</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="allocationReason" /></x-filament::input.wrapper></label>
                </section>
            @endif

            @error('assistant')<div class="fac-alert fac-alert-danger" role="alert"><x-filament::icon icon="heroicon-o-exclamation-circle" style="height: 1.25rem; width: 1.25rem;" /><span>{{ $message }}</span></div>@enderror

            <footer class="fac-footer">
                <x-filament::button type="button" color="gray" wire:click="cancel">{{ __('filament-accounting::actions.cancel') }}</x-filament::button>
                <x-filament::button type="button" icon="heroicon-o-check-circle" wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize" :disabled="! $canFinalize">{{ __('filament-accounting::actions.assign_and_post') }}</x-filament::button>
            </footer>
        @endif
    @endif
</div>
