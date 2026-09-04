<div @class(['fac-assistant', 'fac-assistant-modal' => $this->context === 'modal'])>
    <style>
        .fac-assistant { --fac-border: rgb(var(--gray-200), 1); --fac-surface: #fff; --fac-subtle: rgb(var(--gray-50)); display: grid; gap: 1rem; color: rgb(var(--gray-950)); min-width: 0; }
        .dark .fac-assistant { --fac-border: rgb(var(--gray-700), 1); color: rgb(var(--gray-50)); }
        .fac-assistant-modal { padding: 0 .125rem .25rem; }
        .fac-workspace { align-items: start; display: grid; gap: 1rem; grid-template-columns: 17.5rem minmax(0, 1fr); min-width: 0; }
        .fac-workspace-wide { grid-template-columns: minmax(0, 1fr); }
        .fac-sidebar { min-width: 0; position: sticky; top: .5rem; }
        .fac-workflow { display: grid; gap: 1rem; min-width: 0; }
        .fac-card { background: var(--fac-surface); border: 1px solid var(--fac-border); border-radius: .75rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); padding: 1rem; }
        .dark .fac-assistant { --fac-surface: rgb(var(--gray-900)); --fac-subtle: rgb(var(--gray-950)); }
        .fac-transaction-card { border-left: 4px solid rgb(var(--primary-500)); padding: .9rem 1rem; }
        .fac-transaction-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .85rem 1.5rem; }
        .fac-detail { min-width: 0; }
        .fac-detail-wide { grid-column: span 2; }
        .fac-label { display: block; color: rgb(var(--gray-500)); font-size: .68rem; font-weight: 700; letter-spacing: .045em; line-height: 1.35; text-transform: uppercase; }
        .fac-value { display: block; font-size: .875rem; line-height: 1.4; margin-top: .2rem; overflow-wrap: break-word; }
        .fac-amount { align-items: center; display: inline-flex; flex: 0 0 auto; font-size: 1.35rem; font-variant-numeric: tabular-nums; font-weight: 750; gap: .4rem; white-space: nowrap; }
        .fac-amount-incoming { color: rgb(var(--success-700)); }
        .dark .fac-amount-incoming { color: rgb(var(--success-400)); }
        .fac-amount-outgoing { color: #2563eb; }
        .dark .fac-amount-outgoing { color: #60a5fa; }
        .fac-transaction-summary { align-items: center; display: grid; gap: 1rem 2rem; grid-template-columns: minmax(0, 1fr) auto; }
        .fac-summary-line { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; margin-top: .3rem; }
        .fac-summary-counterparty { font-size: 1rem; font-weight: 700; }
        .fac-details { border-top: 1px solid var(--fac-border); margin-top: .75rem; padding-top: .65rem; }
        .fac-details > summary, .fac-row-details > summary { color: rgb(var(--primary-600)); cursor: pointer; font-size: .75rem; font-weight: 650; list-style-position: inside; width: max-content; }
        .dark .fac-details > summary, .dark .fac-row-details > summary { color: rgb(var(--primary-400)); }
        .fac-assignment-nav { background: var(--fac-subtle); }
        .fac-section-heading { font-size: 1rem; font-weight: 700; line-height: 1.3; }
        .fac-section-copy { color: rgb(var(--gray-500)); font-size: .8rem; line-height: 1.45; margin-top: .2rem; }
        .fac-type-grid { display: grid; gap: .45rem; margin-top: .8rem; }
        .fac-type { align-items: flex-start; background: var(--fac-surface); border: 1px solid var(--fac-border); border-radius: .6rem; display: flex; gap: .7rem; min-width: 0; padding: .75rem; text-align: left; transition: background .15s, border-color .15s, box-shadow .15s; width: 100%; }
        .fac-type:hover { border-color: rgb(var(--primary-400)); }
        .fac-type:focus-visible, .fac-category:focus-within, .fac-field:focus-within { outline: 3px solid rgb(var(--primary-500) / .3); outline-offset: 2px; }
        .fac-type[aria-selected="true"] { background: #eff6ff; border-color: #2563eb; box-shadow: inset 3px 0 #2563eb; color: #1e3a8a; }
        .dark .fac-type { background: #1f2937; border-color: #4b5563; }
        .dark .fac-type[aria-selected="true"] { background: #172554; border-color: #60a5fa; color: #dbeafe; }
        .fac-type-icon { flex: 0 0 auto; height: 1.25rem; margin-top: .1rem; width: 1.25rem; }
        .fac-type-copy { display: grid; gap: .15rem; min-width: 0; }
        .fac-type-copy strong { font-size: .82rem; line-height: 1.3; }
        .fac-type-copy small, .fac-muted, .fac-reasons, .fac-date-line { color: rgb(var(--gray-500)); font-size: .75rem; line-height: 1.4; }
        .fac-type[aria-selected="true"] .fac-type-copy small { color: #1d4ed8; }
        .dark .fac-type[aria-selected="true"] .fac-type-copy small { color: #bfdbfe; }
        .fac-date-line, .fac-reasons { display: block; }
        .fac-toolbar { align-items: end; display: grid; gap: .75rem 1rem; grid-template-columns: minmax(16rem, 1fr) auto auto; margin: 1rem 0; }
        .fac-check { align-items: center; display: inline-flex; gap: .4rem; min-height: 2.5rem; }
        .fac-table-wrap { border: 1px solid var(--fac-border); border-radius: .65rem; overflow-x: auto; }
        .fac-table { border-collapse: collapse; min-width: 50rem; width: 100%; }
        .fac-table th { background: var(--fac-subtle); color: rgb(var(--gray-500)); font-size: .67rem; font-weight: 700; letter-spacing: .035em; padding: .65rem .75rem; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .fac-table td { border-top: 1px solid var(--fac-border); font-size: .82rem; line-height: 1.4; padding: .75rem; vertical-align: top; }
        .fac-table tbody tr:hover { background: rgb(var(--gray-50) / .75); }
        .dark .fac-table tbody tr:hover { background: rgb(var(--gray-800) / .45); }
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
        .fac-category { background: var(--fac-surface); border: 1px solid var(--fac-border); border-radius: .65rem; display: grid; gap: .5rem; padding: .8rem; }
        .fac-category-selected { border-color: rgb(var(--primary-500)); box-shadow: 0 0 0 2px rgb(var(--primary-500) / .15); }
        .fac-category-meta { display: flex; flex-wrap: wrap; gap: .35rem; }
        .fac-split-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .fac-split-list { display: grid; gap: .75rem; }
        .fac-split-row { border: 1px solid var(--fac-border); border-radius: .65rem; padding: .8rem; }
        .fac-split-grid { align-items: end; display: grid; grid-template-columns: 1fr 1.35fr .75fr 1fr auto; gap: .75rem; }
        .fac-field { display: grid; gap: .3rem; }
        .fac-comment-card { padding: .8rem 1rem; }
        .fac-workspace-footer { background: var(--fac-surface); border: 1px solid var(--fac-border); border-radius: .75rem; bottom: 0; box-shadow: 0 -4px 12px rgb(0 0 0 / .08); grid-column: 1 / -1; overflow: hidden; position: sticky; z-index: 10; }
        .fac-workspace-footer .fac-comment-card { border: 0; border-bottom: 1px solid var(--fac-border); border-radius: 0; box-shadow: none; }
        .fac-footer { align-items: center; background: var(--fac-surface); display: flex; flex-wrap: wrap; gap: .65rem; justify-content: center; padding: .85rem; }
        @media (max-width: 68rem) { .fac-workspace { grid-template-columns: 1fr; } .fac-sidebar { position: static; } .fac-type-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fac-toolbar { grid-template-columns: minmax(0, 1fr) auto auto; } .fac-category-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .fac-split-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 42rem) { .fac-transaction-grid, .fac-type-grid, .fac-category-grid, .fac-split-summary, .fac-split-grid, .fac-toolbar { grid-template-columns: 1fr; } .fac-detail-wide { grid-column: auto; } .fac-transaction-summary { align-items: flex-start; grid-template-columns: 1fr; } .fac-amount { font-size: 1.1rem; } .fac-check { min-height: auto; } .fac-footer > * { flex: 1 1 auto; } }
    </style>

    @if (! $statementLine)
        <div class="fac-card">
            <p>{{ __('filament-accounting::fields.select_line') }}</p>
        </div>
    @else
        <section class="fac-card fac-transaction-card" aria-labelledby="fac-transaction-heading">
            <div class="fac-transaction-summary">
                <div>
                    <h2 id="fac-transaction-heading" class="fac-section-heading">{{ __('filament-accounting::fields.transaction_details') }}</h2>
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

        <div @class(['fac-workspace', 'fac-workspace-wide' => $statementLine->source_status->value !== 'booked' || $postedReconciliation])>
        @if ($statementLine->source_status->value === 'booked' && ! $postedReconciliation)
            <aside class="fac-sidebar">
                <section class="fac-card fac-assignment-nav" aria-labelledby="fac-assignment-type-heading">
                    <h2 id="fac-assignment-type-heading" class="fac-section-heading">{{ __('filament-accounting::fields.choose_assignment_type') }}</h2>
                    <div class="fac-type-grid" role="tablist" aria-label="{{ __('filament-accounting::fields.choose_assignment_type') }}">
                        @foreach ([
                            'sales_invoice' => 'heroicon-o-document-currency-euro',
                            'purchase_invoice' => 'heroicon-o-receipt-percent',
                            'posting_rule' => 'heroicon-o-scale',
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
            </aside>
        @endif
        <main class="fac-workflow">

        @if ($statementLine->source_status->value !== 'booked')
            <section class="fac-card">
                <div class="fac-alert" style="margin-bottom: 0;">
                    <x-filament::icon icon="heroicon-o-clock" style="height: 1.25rem; width: 1.25rem;" />
                    <div><strong>{{ __('filament-accounting::fields.pending_transaction') }}</strong><p>{{ __('filament-accounting::fields.assignment_unavailable_pending') }}</p></div>
                </div>
            </section>
        @elseif ($postedReconciliation)
            <section class="fac-card">
                <h2 class="fac-section-heading">{{ __('filament-accounting::fields.posted_assignment') }}</h2>
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
            @if (in_array($this->assignmentType, ['sales_invoice', 'purchase_invoice'], true))
                <section class="fac-card">
                    <h2 class="fac-section-heading">{{ __('filament-accounting::fields.assignment_types.'.$this->assignmentType) }}</h2>
                    <p class="fac-section-copy">{{ __('filament-accounting::fields.invoice_selection_help') }}</p>
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
                    <h2 class="fac-section-heading">{{ __('filament-accounting::fields.assignment_types.posting_rule') }}</h2>
                    <p class="fac-section-copy">{{ __('filament-accounting::fields.posting_rule_selection_help') }}</p>
                    <label class="fac-field" style="margin: .8rem 0;"><span>{{ __('filament-accounting::fields.search_categories') }}</span><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="postingRuleSearch" /></x-filament::input.wrapper></label>
                    <div class="fac-category-grid">
                        @forelse ($categories as $category)
                            <article @class(['fac-category', 'fac-category-selected' => $this->selectedCategoryKey === $category['key']])>
                                <div>
                                    <strong>{{ $category['name'] }}</strong>
                                    @if ($category['explanation'])<p class="fac-muted">{{ $category['explanation'] }}</p>@endif
                                    @if ($category['account_code'])<p class="fac-muted">{{ __('filament-accounting::fields.ledger_account_number', ['number' => $category['account_code']]) }}</p>@endif
                                </div>
                                <div class="fac-category-meta">
                                    @if ($category['allows_tax'] && $category['tax_rate_bp'] !== null)
                                        <span class="fac-badge">{{ __('filament-accounting::fields.default_tax_rate', ['rate' => number_format($category['tax_rate_bp'] / 100, 2, ',', '.')]) }}</span>
                                    @elseif (! $category['allows_tax'])
                                        <span class="fac-badge">{{ __('filament-accounting::fields.not_taxable') }}</span>
                                    @endif
                                </div>
                                <x-filament::button type="button" size="sm" :color="$this->selectedCategoryKey === $category['key'] ? 'success' : 'gray'" wire:click="selectCategory('{{ $category['key'] }}')">{{ $this->selectedCategoryKey === $category['key'] ? __('filament-accounting::fields.selected') : __('filament-accounting::actions.select') }}</x-filament::button>
                            </article>
                        @empty
                            <p class="fac-empty">{{ __('filament-accounting::fields.no_posting_rules') }}</p>
                        @endforelse
                    </div>
                    @if ($validationErrors['selectedCategoryKey'] ?? null)<span class="fac-error">{{ $validationErrors['selectedCategoryKey'] }}</span>@endif
                    @if (is_array($selectedCategory) && $selectedCategory['allows_tax'])
                        <label class="fac-field" style="margin-top: 1rem; max-width: 30rem;">
                            <span>{{ __('filament-accounting::fields.tax_rate') }}</span>
                            <x-filament::input.wrapper><x-filament::input.select wire:model.live="selectedTaxRuleVersionId">@foreach ($taxRates as $taxRate)<option value="{{ $taxRate['id'] }}">{{ number_format($taxRate['rate_bp'] / 100, 2, ',', '.') }} % – {{ $taxRate['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                            <span class="fac-muted">{{ __('filament-accounting::fields.tax_rate_override_help') }}</span>
                            @if ($validationErrors['selectedTaxRuleVersionId'] ?? null)<span class="fac-error">{{ $validationErrors['selectedTaxRuleVersionId'] }}</span>@endif
                        </label>
                    @endif
                </section>
            @else
                <section class="fac-card">
                    <h2 class="fac-section-heading">{{ __('filament-accounting::fields.assignment_types.split') }}</h2>
                    <p class="fac-section-copy">{{ __('filament-accounting::fields.split_transaction_help') }}</p>
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
                                $targets = $type === 'sales_invoice' ? $salesInvoices : ($type === 'purchase_invoice' ? $purchaseInvoices : $categories);
                                $target = $type === 'posting_rule'
                                    ? collect($targets)->firstWhere('key', $allocation['target_id'] ?? null)
                                    : collect($targets)->firstWhere('id', (int) ($allocation['target_id'] ?? 0));
                            @endphp
                            <article class="fac-split-row" wire:key="reconciliation-allocation-{{ $index }}">
                                <div class="fac-split-grid">
                                    <label class="fac-field"><span>{{ __('filament-accounting::fields.assignment_type') }}</span><x-filament::input.wrapper><x-filament::input.select wire:change="changeAllocationType({{ $index }}, $event.target.value)">@foreach (['sales_invoice', 'purchase_invoice', 'posting_rule'] as $splitType)<option value="{{ $splitType }}" @selected($type === $splitType)>{{ __('filament-accounting::fields.assignment_types.'.$splitType) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@if ($validationErrors["allocations.{$index}.type"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.type"] }}</span>@endif</label>
                                    <label class="fac-field">
                                        <span>{{ __('filament-accounting::fields.target') }}</span>
                                        <x-filament::input.wrapper>
                                            @if ($type === 'posting_rule')
                                                <x-filament::input.select wire:change="changeAllocationTarget({{ $index }}, $event.target.value)">
                                                    <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                                    @foreach ($targets as $candidate)<option value="{{ $candidate['key'] }}" @selected(($allocation['target_id'] ?? null) === $candidate['key'])>{{ $candidate['name'] }}</option>@endforeach
                                                </x-filament::input.select>
                                            @else
                                                <x-filament::input.select wire:model.live="allocations.{{ $index }}.target_id">
                                                    <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                                    @foreach ($targets as $candidate)<option value="{{ $candidate['id'] }}">{{ $candidate['number'] ?: $candidate['supplier_invoice_number'] }} · {{ $candidate['party'] }} · {{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['remaining_minor'], $candidate['currency']) }}</option>@endforeach
                                                </x-filament::input.select>
                                            @endif
                                        </x-filament::input.wrapper>
                                        @if ($validationErrors["allocations.{$index}.target_id"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.target_id"] }}</span>@endif
                                    </label>
                                    @if ($type === 'posting_rule' && is_array($target) && $target['allows_tax'])<label class="fac-field"><span>{{ __('filament-accounting::fields.tax_rate') }}</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="allocations.{{ $index }}.tax_rule_version_id">@foreach ($taxRates as $taxRate)<option value="{{ $taxRate['id'] }}">{{ number_format($taxRate['rate_bp'] / 100, 2, ',', '.') }} % – {{ $taxRate['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@if ($validationErrors["allocations.{$index}.tax_rule_version_id"] ?? null)<span class="fac-error">{{ $validationErrors["allocations.{$index}.tax_rule_version_id"] }}</span>@endif</label>@endif
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

        @endif
        </main>
        @if ($statementLine->source_status->value === 'booked' && ! $postedReconciliation)
            <div class="fac-workspace-footer">
                @if ($this->assignmentType !== 'split')
                    <section class="fac-comment-card">
                        <label class="fac-field"><span>{{ __('filament-accounting::fields.reason_optional') }}</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="allocationReason" /></x-filament::input.wrapper></label>
                    </section>
                @endif
                @error('assistant')<div class="fac-alert fac-alert-danger" role="alert" style="margin: .75rem .75rem 0;"><x-filament::icon icon="heroicon-o-exclamation-circle" style="height: 1.25rem; width: 1.25rem;" /><span>{{ $message }}</span></div>@enderror
                <footer class="fac-footer">
                    <x-filament::button type="button" color="gray" wire:click="cancel">{{ __('filament-accounting::actions.cancel') }}</x-filament::button>
                    <x-filament::button type="button" icon="heroicon-o-check-circle" wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize" :disabled="! $canFinalize">{{ __('filament-accounting::actions.assign_and_post') }}</x-filament::button>
                </footer>
            </div>
        @endif
        </div>
    @endif
</div>
