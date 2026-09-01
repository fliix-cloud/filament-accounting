@php
    $optionLengths = collect($accounts)->map(fn ($account) => mb_strlen($account->pickerLabel()));
    $optionLengths->push(mb_strlen((string) __('filament-accounting::fields.select_account')));
    $selectCh = max(16, (int) $optionLengths->max()) + 6;
    $labelStyle = 'font-size: 0.75rem; line-height: 1rem; color: #6b7280; margin-bottom: 0.25rem;';
    $valueStyle = 'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 1.125rem; font-weight: 600; letter-spacing: -0.02em;';
@endphp

<div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 2rem; margin-bottom: 1.5rem;">
    <div style="display: inline-block; width: min(100%, {{ $selectCh }}ch); max-width: 100%;">
        <label for="accounting-bank-transaction-account" class="fi-fo-field-label" style="margin-bottom: 0.25rem; display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.875rem; font-weight: 500;">
            {{ __('filament-accounting::fields.account') }}
            <sup style="color: #dc2626;">*</sup>
        </label>
        <x-filament::input.wrapper style="width: 100%;">
            <x-filament::input.select
                id="accounting-bank-transaction-account"
                wire:model.live="accountId"
                style="width: 100%;"
            >
                <option value="">{{ __('filament-accounting::fields.select_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->pickerLabel() }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        @unless (filled($selectedAccountId))
            <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">
                {{ __('filament-accounting::fields.select_account_help') }}
            </p>
        @endunless
    </div>

    @if ($selectedAccount)
        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 2rem;">
            @if ($summary['booked_balance'])
                <div>
                    <div style="{{ $labelStyle }}">{{ __('filament-accounting::fields.booked_balance') }}</div>
                    <div style="{{ $valueStyle }}">{{ $summary['booked_balance'] }}</div>
                </div>
            @endif
            @if ($summary['available_amount'])
                <div>
                    <div style="{{ $labelStyle }}">{{ __('filament-accounting::fields.available_amount') }}</div>
                    <div style="{{ $valueStyle }}">{{ $summary['available_amount'] }}</div>
                </div>
            @endif
            @if ($summary['pending_count'] > 0)
                <div>
                    <div style="{{ $labelStyle }}">{{ __('filament-accounting::fields.pending_balance') }}</div>
                    <div style="{{ $valueStyle }} @if ($summary['pending_amount_color']) color: {{ $summary['pending_amount_color'] }}; @endif">
                        {{ $summary['pending_amount'] }}
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
