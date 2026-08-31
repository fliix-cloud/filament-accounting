<x-filament-panels::page>
    @if (! $statementLine)
        <x-filament::section>
            <x-slot name="heading">{{ __('filament-accounting::navigation.bank_transactions') }}</x-slot>
            <p>{{ __('filament-accounting::fields.select_line') }}</p>
            @if ($transactionsUrl ?? null)
                <p style="margin-top: 0.75rem;">
                    <a href="{{ $transactionsUrl }}" class="fi-link">
                        {{ __('filament-accounting::navigation.bank_transactions') }}
                    </a>
                </p>
            @endif
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $formattedAmount }}</x-slot>
            <x-slot name="description">
                {{ $statementLine->booking_date?->toDateString() }}
                @if ($statementLine->counterparty_name)
                    · {{ $statementLine->counterparty_name }}
                @endif
                · {{ $statementLine->bankAccount->display_name }}
            </x-slot>

            <div style="display: grid; gap: 0.5rem;">
                <p>{{ $statementLine->purpose ?: __('filament-accounting::fields.no_purpose') }}</p>
                @if ($statementLine->end_to_end_id)
                    <p style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">
                        {{ __('filament-accounting::fields.end_to_end_id') }}: {{ $statementLine->end_to_end_id }}
                    </p>
                @endif
                @if ($sourceUrl)
                    <p>
                        <a href="{{ $sourceUrl }}" target="_blank" rel="noopener" class="fi-link">
                            {{ __('filament-accounting::actions.open_source') }}
                        </a>
                    </p>
                @endif
            </div>
        </x-filament::section>

        @if ($statementLine->source_status->value !== 'booked' && ! $postedReconciliation)
            <x-filament::section>
                <x-slot name="heading">{{ __('filament-accounting::fields.pending_transaction') }}</x-slot>
                <x-slot name="description">{{ __('filament-accounting::fields.pending_transaction_help') }}</x-slot>
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        wire:model="exceptionReason"
                        placeholder="{{ __('filament-accounting::fields.exception_reason') }}"
                    />
                </x-filament::input.wrapper>
            </x-filament::section>
        @endif

        @if ($postedReconciliation)
            <x-filament::section>
                <x-slot name="heading">{{ __('filament-accounting::fields.posted_assignment') }}</x-slot>
                <x-slot name="description">
                    {{ ($postedReconciliation->match_meta['mode'] ?? null) === 'split'
                        ? __('filament-accounting::fields.split_assignment_summary')
                        : __('filament-accounting::fields.direct_assignment_summary') }}
                    @if ($amountMatch === false)
                        · {{ __('filament-accounting::fields.amount_mismatch') }}
                    @elseif ($amountMatch === true)
                        · {{ __('filament-accounting::fields.amount_matched') }}
                    @endif
                </x-slot>

                <div style="display: grid; gap: 0.75rem;">
                    @foreach ($postedAllocations as $allocation)
                        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.75rem; border-bottom: 1px solid rgba(148, 163, 184, 0.25); padding-bottom: 0.75rem;">
                            <div>
                                <p style="font-weight: 600;">
                                    @if ($allocation['url'])
                                        <a href="{{ $allocation['url'] }}" class="fi-link">{{ $allocation['target'] }}</a>
                                    @else
                                        {{ $allocation['target'] }}
                                    @endif
                                </p>
                                <p style="font-size: 0.875rem; color: rgb(100 116 139);">
                                    {{ $allocation['purpose'] }}
                                    @if ($allocation['reason'])
                                        · {{ $allocation['reason'] }}
                                    @endif
                                </p>
                            </div>
                            <p style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 600;">
                                {{ $allocation['amount'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @elseif ($this->mode === 'direct')
            @if ($suggestions !== [])
                <x-filament::section>
                    <x-slot name="heading">{{ __('filament-accounting::fields.suggested_matches') }}</x-slot>
                    <x-slot name="description">{{ __('filament-accounting::fields.suggested_matches_help') }}</x-slot>

                    <div style="display: grid; gap: 0.75rem;">
                        @foreach ($suggestions as $suggestion)
                            @php($suggestedItem = $openItems->firstWhere('id', $suggestion->targetId))
                            @if ($suggestedItem)
                                <button
                                    type="button"
                                    wire:click="chooseOpenItem({{ $suggestion->targetId }})"
                                    style="display: flex; width: 100%; justify-content: space-between; gap: 1rem; border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 0.75rem; padding: 0.75rem; text-align: left;"
                                >
                                    <span>
                                        <strong>{{ $suggestedItem->document?->number ?: $suggestedItem->document?->supplier_invoice_number }}</strong>
                                        · {{ $suggestedItem->party?->displayLabel() }}
                                        <small style="display: block; color: rgb(100 116 139);">
                                            @foreach ($suggestion->reasons as $reason)
                                                {{ __('filament-accounting::fields.match_reasons.'.$reason) }}@if (! $loop->last), @endif
                                            @endforeach
                                        </small>
                                    </span>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 600;">
                                        {{ \FilamentAccounting\Support\MoneyFormatter::format($suggestedItem->remainingMinor(), $suggestedItem->currency) }}
                                    </span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ __('filament-accounting::fields.direct_assignment') }}</x-slot>
                <x-slot name="description">{{ __('filament-accounting::fields.direct_assignment_help') }}</x-slot>

                <div style="display: grid; gap: 1rem;">
                    <div>
                        <label class="fi-fo-field-label">{{ __('filament-accounting::fields.assignment_type') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="directPurpose">
                                @foreach ($purposeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    @if ($this->directPurpose === 'settle_open_item')
                        <div>
                            <label class="fi-fo-field-label">
                                {{ $statementLine->isIncoming()
                                    ? __('filament-accounting::navigation.sales_invoices')
                                    : __('filament-accounting::navigation.purchase_invoices') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="directOpenItemId">
                                    <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                    @foreach ($openItems as $item)
                                        <option value="{{ $item->getKey() }}">
                                            {{ $item->document?->number ?: $item->document?->supplier_invoice_number }}
                                            · {{ $item->party?->displayLabel() }}
                                            · {{ \FilamentAccounting\Support\MoneyFormatter::format($item->remainingMinor(), $item->currency) }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @elseif ($this->directPurpose === 'posting_rule')
                        <div>
                            <label class="fi-fo-field-label">{{ __('filament-accounting::fields.posting_rule') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="directPostingRuleVersionId">
                                    <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                    @foreach ($postingRuleOptions as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @elseif (in_array($this->directPurpose, ['ledger_account', 'transfer'], true))
                        <div>
                            <label class="fi-fo-field-label">{{ __('filament-accounting::fields.ledger_account') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="directLedgerAccountId">
                                    <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                    @foreach ($ledgerAccountOptions as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif

                    <div>
                        <label class="fi-fo-field-label">{{ __('filament-accounting::fields.reason') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="directReason" />
                        </x-filament::input.wrapper>
                    </div>

                    <p style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 600;">
                        {{ __('filament-accounting::fields.assigned_amount') }}: {{ $formattedAmount }}
                    </p>

                    @if ($this->directAssignmentAmountMismatch())
                        <p style="color: rgb(217 119 6); font-weight: 600;">
                            {{ $this->directAssignmentConfirmationBody() }}
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">{{ __('filament-accounting::fields.split_transaction') }}</x-slot>
                <x-slot name="description">{{ __('filament-accounting::fields.split_transaction_help') }}</x-slot>

                <p style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 600; color: {{ ! $this->hasInvalidAllocationAmounts() && $this->remainingMinor() === 0 ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                    {{ __('filament-accounting::fields.remaining') }}: {{ $remaining }}
                </p>
                <p style="margin-top: 0.5rem; font-size: 0.875rem; color: rgb(100 116 139);">
                    {{ __('filament-accounting::fields.signed_allocation_help') }}
                </p>
            </x-filament::section>

            <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1.5rem;">
                @foreach ($this->allocations as $index => $allocation)
                    <x-filament::section>
                        <x-slot name="heading">
                            {{ __('filament-accounting::fields.allocation_number', ['number' => $index + 1]) }}
                        </x-slot>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); align-items: end; gap: 1rem;">
                            <div>
                                <label class="fi-fo-field-label">{{ __('filament-accounting::fields.assignment_type') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="allocations.{{ $index }}.purpose">
                                        @foreach ($purposeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>

                            <div>
                                <label class="fi-fo-field-label">{{ __('filament-accounting::fields.amount') }} ({{ $statementLine->currency }})</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input
                                        type="text"
                                        inputmode="decimal"
                                        wire:model.live.debounce.300ms="allocations.{{ $index }}.amount"
                                    />
                                </x-filament::input.wrapper>
                            </div>

                            @if (($allocation['purpose'] ?? null) === 'settle_open_item')
                                <div>
                                    <label class="fi-fo-field-label">{{ __('filament-accounting::fields.invoice_or_bill') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="allocations.{{ $index }}.open_item_id">
                                            <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                            @foreach ($openItems as $item)
                                                <option value="{{ $item->getKey() }}">
                                                    {{ $item->document?->number ?: $item->document?->supplier_invoice_number }}
                                                    · {{ $item->party?->displayLabel() }}
                                                    · {{ \FilamentAccounting\Support\MoneyFormatter::format($item->remainingMinor(), $item->currency) }}
                                                </option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @elseif (($allocation['purpose'] ?? null) === 'posting_rule')
                                <div>
                                    <label class="fi-fo-field-label">{{ __('filament-accounting::fields.posting_rule') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="allocations.{{ $index }}.posting_rule_version_id">
                                            <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                            @foreach ($postingRuleOptions as $id => $label)
                                                <option value="{{ $id }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @elseif (in_array(($allocation['purpose'] ?? null), ['ledger_account', 'transfer'], true))
                                <div>
                                    <label class="fi-fo-field-label">{{ __('filament-accounting::fields.ledger_account') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="allocations.{{ $index }}.ledger_account_id">
                                            <option value="">{{ __('filament-accounting::fields.select_target') }}</option>
                                            @foreach ($ledgerAccountOptions as $id => $label)
                                                <option value="{{ $id }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @endif

                            <div>
                                <label class="fi-fo-field-label">{{ __('filament-accounting::fields.reason') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" wire:model="allocations.{{ $index }}.reason" />
                                </x-filament::input.wrapper>
                            </div>

                            @if (count($this->allocations) > 2)
                                <x-filament::button color="gray" wire:click="removeAllocation({{ $index }})" type="button">
                                    {{ __('filament-accounting::actions.remove_allocation') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    @endif
</x-filament-panels::page>
