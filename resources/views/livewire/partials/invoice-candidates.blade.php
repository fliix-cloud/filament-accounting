<div class="fac-table-wrap">
    @if ($candidates === [])
        <p class="fac-empty">{{ __('filament-accounting::fields.no_invoice_candidates') }}</p>
    @else
        <table class="fac-table">
            <thead>
                <tr>
                    <th>{{ $candidateType === 'sales_invoice' ? __('filament-accounting::fields.number') : __('filament-accounting::fields.internal_number') }}</th>
                    @if ($candidateType === 'purchase_invoice')
                        <th>{{ __('filament-accounting::fields.supplier_invoice_number') }}</th>
                    @endif
                    <th>{{ $candidateType === 'sales_invoice' ? __('filament-accounting::fields.customer') : __('filament-accounting::fields.supplier') }}</th>
                    <th>{{ __('filament-accounting::fields.invoice_dates') }}</th>
                    <th class="fac-number">{{ __('filament-accounting::fields.gross') }}</th>
                    <th class="fac-number">{{ __('filament-accounting::fields.already_paid') }}</th>
                    <th class="fac-number">{{ __('filament-accounting::fields.open_amount') }}</th>
                    <th>{{ __('filament-accounting::fields.payment_status') }}</th>
                    <th>{{ __('filament-accounting::fields.suggestion') }}</th>
                    <th><span class="sr-only">{{ __('filament-accounting::actions.select') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($candidates as $candidate)
                    <tr @class(['fac-selected-row' => $this->selectedOpenItemId === $candidate['id']])>
                        <td>
                            <strong>{{ $candidate['number'] ?: __('filament-accounting::fields.not_available') }}</strong>
                        </td>
                        @if ($candidateType === 'purchase_invoice')
                            <td>{{ $candidate['supplier_invoice_number'] ?: __('filament-accounting::fields.not_available') }}</td>
                        @endif
                        <td>{{ $candidate['party'] ?: __('filament-accounting::fields.unknown_party') }}</td>
                        <td>
                            <span class="fac-date-line">{{ __('filament-accounting::fields.issue_date') }}: {{ $candidate['issue_date'] ?: __('filament-accounting::fields.not_available') }}</span>
                            @if ($candidateType === 'purchase_invoice')
                                <span class="fac-date-line">{{ __('filament-accounting::fields.receipt_date') }}: {{ $candidate['receipt_date'] ?: __('filament-accounting::fields.not_available') }}</span>
                            @endif
                            <span class="fac-date-line">{{ __('filament-accounting::fields.due_date') }}: {{ $candidate['due_date'] ?: __('filament-accounting::fields.not_available') }}</span>
                        </td>
                        <td class="fac-number">{{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['gross_minor'], $candidate['currency']) }}</td>
                        <td class="fac-number">{{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['settled_minor'], $candidate['currency']) }}</td>
                        <td class="fac-number fac-open-amount">{{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['remaining_minor'], $candidate['currency']) }}</td>
                        <td>
                            <span class="fac-badge">{{ __('filament-accounting::statuses.payment.'.$candidate['payment_status']) }}</span>
                        </td>
                        <td>
                            @if ($candidate['score'] > 0)
                                <span class="fac-badge fac-confidence-{{ $candidate['confidence'] }}">
                                    {{ __('filament-accounting::fields.confidence.'.$candidate['confidence']) }}
                                    · {{ $candidate['score'] }}
                                </span>
                                <span class="fac-reasons">
                                    {{ __('filament-accounting::fields.suggestion_rank', ['rank' => $loop->iteration]) }}:
                                    @foreach ($candidate['reasons'] as $reason)
                                        {{ __('filament-accounting::fields.match_reasons.'.$reason) }}@if (! $loop->last), @endif
                                    @endforeach
                                    @if ($candidate['ambiguous'])
                                        · {{ __('filament-accounting::fields.ambiguous_match') }}
                                    @endif
                                </span>
                            @else
                                <span class="fac-muted">{{ __('filament-accounting::fields.no_suggestion') }}</span>
                            @endif
                        </td>
                        <td>
                            <x-filament::button
                                type="button"
                                size="sm"
                                :color="$this->selectedOpenItemId === $candidate['id'] ? 'success' : 'gray'"
                                wire:click="selectOpenItem({{ $candidate['id'] }})"
                            >
                                {{ $this->selectedOpenItemId === $candidate['id']
                                    ? __('filament-accounting::fields.selected')
                                    : __('filament-accounting::actions.select') }}
                            </x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
