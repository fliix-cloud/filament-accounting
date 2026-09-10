<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <title>{{ __('filament-accounting::invoice.title') }} {{ $snapshot['number'] ?? '' }}</title>
        <style>
            @page { margin: 11mm 16.5mm 25mm; }
            body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; }
            table { border-collapse: collapse; width: 100%; }
            td, th { vertical-align: top; }
            .masthead { height: 40mm; }
            .company { font-weight: bold; font-size: 19pt; margin: 0 0 3mm; }
            .subtitle { font-size: 11pt; }
            .logo { width: 68mm; max-height: 25mm; }
            .address-block { height: 54mm; }
            .recipient { width: 62%; }
            .sender-line { font-size: 7pt; text-decoration: underline; margin-bottom: 2mm; }
            .contact { width: 38%; padding-top: 8mm; }
            .metadata { margin-top: 6mm; font-size: 9pt; }
            .metadata td { padding: 0.3mm 0; }
            .metadata td:first-child { width: 50%; }
            h1 { font-size: 14pt; margin: 4mm 0 7mm; }
            .intro { margin: 0 0 6mm; }
            .intro p { margin: 0 0 3mm; }
            .items { font-size: 8pt; table-layout: fixed; }
            .items thead { display: table-header-group; }
            .items tr { page-break-inside: avoid; }
            .items th { background: #d8d8d8; font-weight: normal; padding: 1.4mm 0.5mm; text-align: left; }
            .items td { padding: 2mm 0.5mm; border-bottom: 0.2mm solid #ddd; overflow-wrap: break-word; }
            .items .right, .totals .right { text-align: right; }
            .items .center { text-align: center; }
            .items p { margin: 0 0 2mm; }
            .items ul, .items ol { margin: 0 0 2mm; padding-left: 4mm; }
            .totals { margin-top: 0; page-break-inside: avoid; }
            .totals td { padding: 2mm 0; }
            .grand td { font-weight: bold; background: #d8d8d8; padding: 1mm 0; }
            .payment { page-break-inside: avoid; line-height: 1.4; }
            .revision { margin-bottom: 4mm; }
            footer { position: fixed; bottom: -14mm; left: 0; right: 0; border-top: 0.2mm solid #ccc; padding-top: 1mm; font-size: 7pt; }
            footer td { width: 50%; }
        </style>
    </head>
    <body>
        <footer>
            <table><tr><td>
                @if(filled($seller['invoice_bank_name'] ?? null)){{ __('filament-accounting::invoice.bank') }}: {{ $seller['invoice_bank_name'] }}<br />@endif
                @if(filled($seller['invoice_iban'] ?? null))IBAN: {{ $seller['invoice_iban'] }}<br />@endif
                @if(filled($seller['invoice_bic'] ?? null))BIC: {{ $seller['invoice_bic'] }}@endif
            </td><td>
                @if(filled($seller['tax_number'] ?? null)){{ __('filament-accounting::fields.tax_number') }}: {{ $seller['tax_number'] }}<br />@endif
                @if(filled($seller['vat_id'] ?? null)){{ __('filament-accounting::fields.vat_id') }}: {{ $seller['vat_id'] }}@endif
            </td></tr></table>
        </footer>
        <table class="masthead"><tr><td>
            <div class="company">{{ $seller['legal_name'] ?? '' }}</div>
            <div class="subtitle">{{ $seller['invoice_subtitle'] ?? '' }}</div>
        </td><td style="width: 68mm; text-align: right">
            @if(filled($seller['invoice_logo_data'] ?? null))<img class="logo" src="{{ $seller['invoice_logo_data'] }}" alt="" />@endif
        </td></tr></table>
        <table class="address-block"><tr><td class="recipient">
            <div class="sender-line">{{ $seller['trading_name'] ?? $seller['legal_name'] ?? '' }} - {{ $seller['address_line1'] ?? '' }} - {{ $seller['postal_code'] ?? '' }} {{ $seller['city'] ?? '' }}</div>
            {{ $buyer['legal_name'] ?? '' }}<br />
            {{ $address['line1'] ?? '' }}<br />
            @if(filled($address['line2'] ?? null)){{ $address['line2'] }}<br />@endif
            {{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }}
            @if(($address['country_code'] ?? $buyer['country_code'] ?? '') !== ($seller['country_code'] ?? ''))<br />{{ $address['country_code'] ?? $buyer['country_code'] ?? '' }}@endif
        </td><td class="contact">
            <strong>{{ __('filament-accounting::invoice.contact') }}:</strong><br />
            {{ $seller['legal_name'] ?? '' }}<br />{{ $seller['address_line1'] ?? '' }}<br />
            @if(filled($seller['address_line2'] ?? null)){{ $seller['address_line2'] }}<br />@endif
            {{ $seller['postal_code'] ?? '' }} {{ $seller['city'] ?? '' }}<br /><br />
            @if(filled($seller['email'] ?? null)){{ __('filament-accounting::fields.email') }}: {{ $seller['email'] }}<br />@endif
            @if(filled($seller['phone'] ?? null)){{ __('filament-accounting::fields.phone') }}: {{ $seller['phone'] }}<br />@endif
            <table class="metadata">
                <tr><td>{{ __('filament-accounting::invoice.date') }}:</td><td>{{ $date($snapshot['issue_date'] ?? null) }}</td></tr>
                <tr><td>{{ __('filament-accounting::invoice.number') }}:</td><td>{{ $snapshot['number'] ?? '' }}</td></tr>
                @if(filled($buyer['external_reference'] ?? null))<tr><td>{{ __('filament-accounting::invoice.customer_number') }}:</td><td>{{ $buyer['external_reference'] }}</td></tr>@endif
                @if(filled($seller['invoice_contact_name'] ?? null))<tr><td>{{ __('filament-accounting::invoice.contact_person') }}:</td><td>{{ $seller['invoice_contact_name'] }}</td></tr>@endif
            </table>
        </td></tr></table>
        <h1>{{ __('filament-accounting::invoice.title') }}</h1>
        @if(isset($snapshot['invoice_version']))
            <div class="revision">{{ __('filament-accounting::fields.invoice_version') }} {{ $snapshot['invoice_version'] }} — {{ $snapshot['correction_reason'] ?? '' }}</div>
        @endif
        <div class="intro"><p>{{ __('filament-accounting::invoice.salutation') }}</p><p>{{ __('filament-accounting::invoice.intro') }}</p></div>
        <table class="items">
            <thead><tr>
                <th style="width: 4%">{{ __('filament-accounting::invoice.position') }}</th>
                <th class="center" style="width: 7%">{{ __('filament-accounting::invoice.quantity') }}</th>
                <th style="width: 6%">{{ __('filament-accounting::fields.unit') }}</th>
                <th style="width: 12%">{{ __('filament-accounting::invoice.sku') }}</th>
                <th style="width: 39%">{{ __('filament-accounting::invoice.description') }}</th>
                <th class="right" style="width: 16%">{{ __('filament-accounting::fields.unit_price') }}</th>
                <th class="right" style="width: 16%">{{ __('filament-accounting::invoice.line_total') }}</th>
            </tr></thead>
            <tbody>@foreach($lines as $line)<tr>
                <td>{{ $loop->iteration }}</td>
                <td class="center">{{ $line['display_quantity'] }}</td>
                <td>{{ $line['display_unit'] }}</td>
                <td>{{ $line['sku'] ?? '' }}</td>
                <td>{!! $line['description'] !!}</td>
                <td class="right">{{ $money((int) ($line['unit_price_minor'] ?? 0)) }}</td>
                <td class="right">{{ $money((int) ($line['net_minor'] ?? 0)) }}</td>
            </tr>@endforeach</tbody>
        </table>
        <table class="totals">
            <tr><td>{{ __('filament-accounting::invoice.subtotal') }}</td><td class="right">{{ $money((int) ($snapshot['net_minor'] ?? 0)) }}</td></tr>
            @foreach($taxes as $tax)<tr><td>{{ __('filament-accounting::invoice.tax', ['rate' => str_replace('.', ',', (string) ($tax['rate_bp'] / 100)), 'net' => $money($tax['net_minor'])]) }}@if(filled($tax['reason'])) — {{ $tax['reason'] }}@endif</td><td class="right">{{ $money($tax['tax_minor']) }}</td></tr>@endforeach
            <tr class="grand"><td>{{ __('filament-accounting::invoice.total') }}</td><td class="right">{{ $money((int) ($snapshot['gross_minor'] ?? 0)) }}</td></tr>
        </table>
        <div class="payment">
            {{ __('filament-accounting::invoice.payment_method') }}: {{ __('filament-accounting::invoice.payment_methods.'.($snapshot['payment']['method'] ?? 'credit_transfer')) }}<br />
            @if(filled($snapshot['payment']['mandate_reference'] ?? null)){{ __('filament-accounting::invoice.mandate') }}: {{ $snapshot['payment']['mandate_reference'] }}<br />@endif
            @if(filled($snapshot['due_date'] ?? null)){{ __('filament-accounting::fields.due_date') }}: {{ $date($snapshot['due_date']) }}<br />@endif
            @if(filled($snapshot['supply_date'] ?? null) && $snapshot['supply_date'] !== ($snapshot['issue_date'] ?? null)){{ __('filament-accounting::fields.supply_date') }}: {{ $date($snapshot['supply_date']) }}@else{{ __('filament-accounting::invoice.supply_same_as_issue') }}@endif
        </div>
    </body>
</html>
