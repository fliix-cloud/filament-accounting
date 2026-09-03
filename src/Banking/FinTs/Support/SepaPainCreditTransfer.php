<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

final class SepaPainCreditTransfer
{
    public function toXml(
        string $schema,
        string $messageId,
        string $paymentId,
        string $debtorName,
        string $debtorIban,
        ?string $debtorBic,
        string $creditorName,
        string $creditorIban,
        ?string $creditorBic,
        string $amount,
        string $currency,
        ?string $purpose,
        ?string $endToEndId,
    ): string {
        $isV09 = str_contains($schema, 'pain.001.001.09');
        $bicTag = $isV09 ? 'BICFI' : 'BIC';
        $amount = Money::normalize($amount);
        $currency = strtoupper($currency ?: 'EUR');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $document = $dom->createElement('Document');
        $document->setAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:'.$schema);
        $dom->appendChild($document);

        $init = $document->appendChild($dom->createElement('CstmrCdtTrfInitn'));
        $header = $init->appendChild($dom->createElement('GrpHdr'));
        $this->text($dom, $header, 'MsgId', $this->id($messageId));
        $this->text($dom, $header, 'CreDtTm', now()->format('Y-m-d\TH:i:sP'));
        $this->text($dom, $header, 'NbOfTxs', '1');
        $this->text($dom, $header, 'CtrlSum', $amount);
        $initg = $header->appendChild($dom->createElement('InitgPty'));
        $this->text($dom, $initg, 'Nm', $this->name($debtorName));

        $payment = $init->appendChild($dom->createElement('PmtInf'));
        $this->text($dom, $payment, 'PmtInfId', $this->id($paymentId));
        $this->text($dom, $payment, 'PmtMtd', 'TRF');
        $this->text($dom, $payment, 'NbOfTxs', '1');
        $this->text($dom, $payment, 'CtrlSum', $amount);
        $svc = $payment->appendChild($dom->createElement('PmtTpInf'))
            ->appendChild($dom->createElement('SvcLvl'));
        $this->text($dom, $svc, 'Cd', 'SEPA');

        $execution = $payment->appendChild($dom->createElement('ReqdExctnDt'));
        if ($isV09) {
            $this->text($dom, $execution, 'Dt', '1999-01-01');
        } else {
            $execution->appendChild($dom->createTextNode('1999-01-01'));
        }

        $dbtr = $payment->appendChild($dom->createElement('Dbtr'));
        $this->text($dom, $dbtr, 'Nm', $this->name($debtorName));
        $this->iban($dom, $payment, 'DbtrAcct', $debtorIban);
        $this->agent($dom, $payment, 'DbtrAgt', $bicTag, $debtorBic);
        $this->text($dom, $payment, 'ChrgBr', 'SLEV');

        $tx = $payment->appendChild($dom->createElement('CdtTrfTxInf'));
        $this->text($dom, $tx->appendChild($dom->createElement('PmtId')), 'EndToEndId', $this->endToEndId($endToEndId));
        $instd = $tx->appendChild($dom->createElement('Amt'))
            ->appendChild($dom->createElement('InstdAmt', $amount));
        $instd->setAttribute('Ccy', $currency);
        $this->agent($dom, $tx, 'CdtrAgt', $bicTag, $creditorBic);
        $cdtr = $tx->appendChild($dom->createElement('Cdtr'));
        $this->text($dom, $cdtr, 'Nm', $this->name($creditorName));
        $this->iban($dom, $tx, 'CdtrAcct', $creditorIban);

        $remittance = $this->remittance($purpose);
        if ($remittance !== null) {
            $this->text($dom, $tx->appendChild($dom->createElement('RmtInf')), 'Ustrd', $remittance);
        }

        $xml = $dom->saveXML();
        if (! is_string($xml) || $xml === '') {
            throw new \RuntimeException('Failed to build SEPA pain.001 XML.');
        }

        return $xml;
    }

    private function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($dom->createElement($name))->appendChild($dom->createTextNode($value));
    }

    private function iban(DOMDocument $dom, DOMElement $parent, string $wrapper, string $iban): void
    {
        $this->text(
            $dom,
            $parent->appendChild($dom->createElement($wrapper))
                ->appendChild($dom->createElement('Id')),
            'IBAN',
            Iban::normalize($iban),
        );
    }

    private function agent(DOMDocument $dom, DOMElement $parent, string $wrapper, string $bicTag, ?string $bic): void
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $bic) ?? '');
        if ($normalized === '') {
            return;
        }

        $this->text(
            $dom,
            $parent->appendChild($dom->createElement($wrapper))
                ->appendChild($dom->createElement('FinInstnId')),
            $bicTag,
            $normalized,
        );
    }

    private function id(string $value): string
    {
        $compact = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';
        if ($compact === '') {
            $compact = str_replace('-', '', (string) Str::uuid());
        }

        return substr($compact, 0, 35);
    }

    private function endToEndId(?string $value): string
    {
        if (! filled($value)) {
            return 'NOTPROVIDED';
        }

        $id = $this->id($value);

        return $id !== '' ? $id : 'NOTPROVIDED';
    }

    private function name(string $value): string
    {
        return $this->sepaText($value, 70);
    }

    private function remittance(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $text = $this->sepaText($value, 140);

        return $text !== '' ? $text : null;
    }

    private function sepaText(string $value, int $max): string
    {
        $value = strtr($value, [
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'à' => 'a', 'á' => 'a', 'â' => 'a',
            '&' => 'und',
        ]);
        $value = preg_replace("/[^a-zA-Z0-9\\/\\-?:().,'+ ]/u", ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }
}
