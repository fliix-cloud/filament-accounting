<x-filament-panels::page>
    @if (! $statementLine)
        <x-filament::section>
            <x-slot name="heading">{{ __('filament-accounting::navigation.bank_transactions') }}</x-slot>
            <p>{{ __('filament-accounting::fields.select_line') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $formattedAmount }}</x-slot>
            <x-slot name="description">
                {{ $statementLine->booking_date?->toDateString() }}
                @if ($statementLine->counterparty_name)
                    · {{ $statementLine->counterparty_name }}
                @endif
            </x-slot>
            <p>{{ $statementLine->purpose }}</p>
            <p style="margin-top: 0.75rem; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 600;">
                {{ __('filament-accounting::fields.remaining') }}: {{ $remaining }}
            </p>
        </x-filament::section>

        @if ($remaining !== null && $this->remainingMinor() !== 0)
            <x-filament::section>
                <p>{{ __('filament-accounting::validation.splits_must_balance') }}</p>
            </x-filament::section>
        @endif

        <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1.5rem;">
            @foreach ($this->splits as $index => $split)
                <x-filament::section>
                    <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem;">
                        <div>
                            <label class="fi-fo-field-label">{{ __('filament-accounting::fields.type') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="splits.{{ $index }}.purpose">
                                    <option value="settle_open_item">{{ __('filament-accounting::fields.open_item') }}</option>
                                    <option value="bank_fee">{{ __('filament-accounting::fields.bank_fee') }}</option>
                                    <option value="posting_rule">{{ __('filament-accounting::navigation.posting_rules') }}</option>
                                    <option value="ledger_account">{{ __('filament-accounting::fields.account') }}</option>
                                    <option value="suspense">{{ __('filament-accounting::statuses.reconciliation.review') }}</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-label">{{ __('filament-accounting::fields.amount') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="number"
                                    wire:model.live="splits.{{ $index }}.amount_minor"
                                />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-label">{{ __('filament-accounting::fields.open_item') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="splits.{{ $index }}.open_item_id">
                                    <option value="">—</option>
                                    @foreach ($openItems as $item)
                                        <option value="{{ $item->getKey() }}">
                                            {{ $item->document?->number }} ({{ $item->remainingMinor() }})
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <x-filament::button color="gray" wire:click="removeSplit({{ $index }})" type="button">
                            {{ __('filament-accounting::actions.remove_split') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
