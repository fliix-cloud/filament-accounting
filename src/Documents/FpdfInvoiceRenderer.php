<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\RichText;

final class FpdfInvoiceRenderer implements InvoiceRenderer
{
    public function key(): string
    {
        return 'classic';
    }

    public function version(): string
    {
        return '1';
    }

    public function render(array $snapshot): string
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(18, 18, 18);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetTitle($this->encode('Invoice '.($snapshot['number'] ?? '')));
        $pdf->SetCreator('filament-accounting/'.$this->version());

        $seller = (array) ($snapshot['seller'] ?? []);
        $buyer = (array) ($snapshot['buyer'] ?? []);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(120, 9, $this->encode((string) ($seller['legal_name'] ?? '')), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 20);
        $pdf->Cell(52, 9, 'RECHNUNG', 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($this->addressLines($seller) as $line) {
            $pdf->Cell(120, 5, $this->encode($line), 0, 1);
        }

        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(95, 6, 'Rechnung an', 0, 0);
        $pdf->Cell(38, 6, 'Rechnungsnummer', 0, 0);
        $pdf->Cell(39, 6, $this->encode((string) ($snapshot['number'] ?? '')), 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(95, 6, $this->encode((string) ($buyer['legal_name'] ?? $buyer['display_name'] ?? '')), 0, 0);
        $pdf->Cell(38, 6, 'Rechnungsdatum', 0, 0);
        $pdf->Cell(39, 6, $this->encode((string) ($snapshot['issue_date'] ?? '')), 0, 1, 'R');
        foreach ($this->addressLines($buyer) as $line) {
            $pdf->Cell(95, 5, $this->encode($line), 0, 1);
        }

        $pdf->Ln(10);
        $pdf->SetFillColor(235, 238, 242);
        $pdf->SetFont('Helvetica', 'B', 9);
        foreach ([['Pos.', 12], ['Beschreibung', 88], ['Menge', 20], ['Netto', 25], ['Steuer', 27]] as [$label, $width]) {
            $pdf->Cell($width, 7, $this->encode($label), 0, 0, $label === 'Beschreibung' ? 'L' : 'R', true);
        }
        $pdf->Ln();

        $currency = (string) ($snapshot['currency'] ?? 'EUR');
        $pdf->SetFont('Helvetica', '', 9);
        foreach ((array) ($snapshot['lines'] ?? []) as $index => $line) {
            $line = (array) $line;
            $startY = $pdf->GetY();
            $pdf->SetXY(18, $startY);
            $pdf->Cell(12, 7, (string) ($index + 1), 0, 0, 'R');
            $descriptionBottom = $this->writeRichDescription($pdf, (string) ($line['description'] ?? ''), 30, $startY, 88);
            $pdf->SetXY(118, $startY);
            $pdf->Cell(20, 7, (string) ($line['quantity'] ?? '1'), 0, 0, 'R');
            $pdf->Cell(25, 7, $this->money((int) ($line['net_minor'] ?? 0), $currency), 0, 0, 'R');
            $pdf->Cell(27, 7, number_format(((int) ($line['tax_rate_bp'] ?? 0)) / 100, 2, ',', '.').' %', 0, 0, 'R');
            $pdf->SetXY(18, max($startY + 7, $descriptionBottom) + 1.5);
        }

        $pdf->Ln(5);
        $pdf->SetX(118);
        $pdf->Cell(35, 6, 'Netto', 0, 0);
        $pdf->Cell(37, 6, $this->money((int) ($snapshot['net_minor'] ?? 0), $currency), 0, 1, 'R');
        $pdf->SetX(118);
        $pdf->Cell(35, 6, 'Umsatzsteuer', 0, 0);
        $pdf->Cell(37, 6, $this->money((int) ($snapshot['tax_minor'] ?? 0), $currency), 0, 1, 'R');
        $pdf->SetX(118);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(35, 7, 'Gesamt', 'T', 0);
        $pdf->Cell(37, 7, $this->money((int) ($snapshot['gross_minor'] ?? 0), $currency), 'T', 1, 'R');

        $pdf->Ln(14);
        $pdf->SetFont('Helvetica', '', 8);
        $footer = array_filter([
            $seller['vat_id'] ?? null,
            $seller['tax_number'] ?? null,
            $seller['invoice_bank_name'] ?? null,
            $seller['invoice_iban'] ?? null,
            $seller['invoice_bic'] ?? null,
        ]);
        $pdf->MultiCell(172, 5, $this->encode(implode('  |  ', array_map('strval', $footer))));

        return $pdf->Output('S');
    }

    /** @param array<string, mixed> $party */
    private function addressLines(array $party): array
    {
        if (isset($party['addresses'][0]) && is_array($party['addresses'][0])) {
            $party = array_merge($party, $party['addresses'][0]);
        }

        return array_values(array_filter([
            $party['address_line1'] ?? $party['line1'] ?? null,
            $party['address_line2'] ?? $party['line2'] ?? null,
            trim((string) ($party['postal_code'] ?? '').' '.(string) ($party['city'] ?? '')),
            $party['country_code'] ?? null,
        ], fn (mixed $value): bool => filled($value)));
    }

    private function money(int $minor, string $currency): string
    {
        return number_format((float) ExactMoney::ofMinor($minor, $currency)->decimalString(), 2, ',', '.').' '.$currency;
    }

    private function writeRichDescription(\FPDF $pdf, string $html, float $x, float $y, float $width): float
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><body>'.(RichText::sanitize($html) ?? '').'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tokens = [];
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMNode) {
            foreach ($body->childNodes as $node) {
                $this->collectRichTokens($node, '', $tokens);
            }
        }

        $pdf->SetXY($x, $y);
        $pdf->SetRightMargin($pdf->GetPageWidth() - ($x + $width));
        $atLineStart = true;
        foreach ($tokens as $token) {
            if ($token['newline']) {
                if (! $atLineStart) {
                    $pdf->Ln(4.5);
                    $pdf->SetX($x);
                }
                $atLineStart = true;

                continue;
            }

            $pdf->SetFont('Helvetica', $token['style'], 9);
            $pdf->Write(4.5, $this->encode($token['text']));
            $atLineStart = false;
        }

        $bottom = $pdf->GetY() + ($atLineStart ? 0 : 4.5);
        $pdf->SetRightMargin(18);
        $pdf->SetFont('Helvetica', '', 9);

        return $bottom;
    }

    /** @param list<array{text: string, style: string, newline: bool}> $tokens */
    private function collectRichTokens(\DOMNode $node, string $style, array &$tokens): void
    {
        if ($node instanceof \DOMText) {
            $text = preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '';
            if ($text !== '') {
                $tokens[] = ['text' => $text, 'style' => $style, 'newline' => false];
            }

            return;
        }

        $name = strtolower($node->nodeName);
        if (in_array($name, ['p', 'li'], true) && $tokens !== []) {
            $tokens[] = ['text' => '', 'style' => '', 'newline' => true];
        }
        if ($name === 'br') {
            $tokens[] = ['text' => '', 'style' => '', 'newline' => true];

            return;
        }
        if ($name === 'li') {
            $tokens[] = ['text' => '• ', 'style' => '', 'newline' => false];
        }

        $nextStyle = $style;
        if (in_array($name, ['strong', 'b'], true) && ! str_contains($nextStyle, 'B')) {
            $nextStyle .= 'B';
        }
        if (in_array($name, ['em', 'i'], true) && ! str_contains($nextStyle, 'I')) {
            $nextStyle .= 'I';
        }

        foreach ($node->childNodes as $child) {
            $this->collectRichTokens($child, $nextStyle, $tokens);
        }
    }

    private function encode(string $value): string
    {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }
}
