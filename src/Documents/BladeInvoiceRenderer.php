<?php

namespace FilamentAccounting\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\RichText;
use Illuminate\Support\Carbon;

final class BladeInvoiceRenderer implements InvoiceRenderer
{
    public function key(): string
    {
        return 'blade';
    }

    public function version(): string
    {
        return '1';
    }

    /** @param array<string, mixed> $snapshot */
    public function render(array $snapshot): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setDefaultFont('Helvetica');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($this->html($snapshot), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    /** @param array<string, mixed> $snapshot */
    public function html(array $snapshot): string
    {
        $currency = (string) ($snapshot['currency'] ?? 'EUR');
        $seller = (array) ($snapshot['seller'] ?? []);
        $buyer = (array) ($snapshot['buyer'] ?? []);
        $locale = explode('-', str_replace('_', '-', (string) ($seller['locale'] ?? app()->getLocale())))[0];
        $address = (array) ($buyer['addresses'][0] ?? []);
        $lines = [];
        $taxes = [];
        foreach ($snapshot['lines'] ?? [] as $line) {
            $line['description'] = RichText::sanitize($line['description'] ?? '') ?? '';
            $quantity = (string) ($line['quantity'] ?? '1');
            $line['display_quantity'] = str_replace('.', ',', str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity);
            $unit = (string) ($line['unit'] ?? '');
            $unitKey = 'filament-accounting::invoice.units.'.$unit;
            $line['display_unit'] = trans()->has($unitKey, $locale) ? __($unitKey, [], $locale) : $unit;
            $lines[] = $line;
            $key = (string) ($line['tax_rate_bp'] ?? 0).'|'.($line['tax_reason'] ?? '');
            $taxes[$key] ??= ['rate_bp' => (int) ($line['tax_rate_bp'] ?? 0), 'net_minor' => 0, 'tax_minor' => 0, 'reason' => $line['tax_reason'] ?? null];
            $taxes[$key]['net_minor'] += (int) ($line['net_minor'] ?? 0);
            $taxes[$key]['tax_minor'] += (int) ($line['tax_minor'] ?? 0);
        }
        $money = fn (int $minor): string => $this->decimal(ExactMoney::ofMinor($minor, $currency)->decimalString()).' '.($currency === 'EUR' ? '€' : $currency);
        $date = fn (?string $value): string => filled($value) ? Carbon::parse($value)->format('d.m.Y') : '';

        $previousLocale = app()->getLocale();
        app()->setLocale($locale);
        try {
            return view('filament-accounting::documents.invoice', compact('snapshot', 'seller', 'buyer', 'address', 'lines', 'taxes', 'money', 'date'))->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    private function decimal(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) ?? $whole;

        return $whole.','.$fraction;
    }
}
