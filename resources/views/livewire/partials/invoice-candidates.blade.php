<div class="fac-table-wrap">
    @if ($candidates === [])
        <p class="fac-empty">{{ __('filament-accounting::fields.no_invoice_candidates') }}</p>
    @else
        <table class="fac-table">
            <thead>
                <tr>
                    <th>{{ $candidateType === 'sales_invoice' ? __('filament-accounting::fields.number') : __('filament-accounting::fields.internal_number') }}</th>
                    <th>{{ $candidateType === 'purchase_invoice' ? __('filament-accounting::fields.supplier') : __('filament-accounting::fields.customer') }}</th>
                    <th>{{ __('filament-accounting::fields.open_amount') }}</th>
                    <th>{{ __('filament-accounting::fields.payment_status') }}</th>
                    <th>{{ __('filament-accounting::fields.suggestion') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($candidates as $candidate)
                    <tr @class(['fac-selected-row' => $this->selectedOpenItemId === $candidate['id']]) wire:key="reconciliation-candidate-{{ $candidateType }}-{{ $candidate['id'] }}">
                        <td>
                            <strong>{{ $candidate['number'] ?: __('filament-accounting::fields.not_available') }}</strong>
                            @if ($candidateType === 'purchase_invoice' && $candidate['supplier_invoice_number'])
                                <span class="fac-date-line">{{ $candidate['supplier_invoice_number'] }}</span>
                            @endif
                            <details class="fac-row-details">
                                <summary>{{ __('filament-accounting::fields.show_details') }}</summary>
                                <div class="fac-detail-grid">
                                    <span>{{ __('filament-accounting::fields.issue_date') }}: {{ $candidate['issue_date'] ?: __('filament-accounting::fields.not_available') }}</span>
                                    <span>{{ __('filament-accounting::fields.due_date') }}: {{ $candidate['due_date'] ?: __('filament-accounting::fields.not_available') }}</span>
                                    <span>{{ __('filament-accounting::fields.gross') }}: {{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['gross_minor'], $candidate['currency']) }}</span>
                                    <span>{{ __('filament-accounting::fields.already_paid') }}: {{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['settled_minor'], $candidate['currency']) }}</span>
                                </div>
                            </details>
                        </td>
                        <td>{{ $candidate['party'] ?: __('filament-accounting::fields.unknown_party') }}</td>
                        <td class="fac-number fac-open-amount">{{ \FilamentAccounting\Support\MoneyFormatter::format($candidate['remaining_minor'], $candidate['currency']) }}</td>
                        <td><span class="fac-badge">{{ __('filament-accounting::statuses.payment.'.$candidate['payment_status']) }}</span></td>
                        <td>
                            @if ($candidate['score'] > 0)
                                <span @class(['fac-badge', 'fac-confidence-high' => $candidate['confidence'] === 'high', 'fac-confidence-medium' => $candidate['confidence'] === 'medium', 'fac-confidence-low' => $candidate['confidence'] === 'low'])>
                                    {{ __('filament-accounting::fields.confidence.'.$candidate['confidence']) }} · {{ $candidate['score'] }}
                                </span>
                                <details class="fac-row-details">
                                    <summary>{{ __('filament-accounting::fields.suggestion_rank', ['rank' => $loop->iteration]) }} · {{ __('filament-accounting::fields.show_details') }}</summary>
                                    <span class="fac-reasons">@foreach ($candidate['reasons'] as $reason){{ __('filament-accounting::fields.match_reasons.'.$reason) }}@if (! $loop->last) · @endif @endforeach @if ($candidate['ambiguous']) · {{ __('filament-accounting::fields.ambiguous_match') }} @endif</span>
                                </details>
                            @else
                                <span class="fac-muted">{{ __('filament-accounting::fields.no_suggestion') }}</span>
                            @endif
                        </td>
                        <td><x-filament::button type="button" size="sm" :color="$this->selectedOpenItemId === $candidate['id'] ? 'success' : 'gray'" wire:click="selectOpenItem({{ $candidate['id'] }})">{{ $this->selectedOpenItemId === $candidate['id'] ? __('filament-accounting::fields.selected') : __('filament-accounting::actions.select') }}</x-filament::button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
