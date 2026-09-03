<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use DOMDocument;
use DOMElement;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;

final class SepaPainDirectDebit
{
    public const SUPPORTED_SCHEMAS = [
        'pain.008.001.08',
        'pain.008.001.02',
    ];

    public function toXml(string $schema, BankDirectDebit $debit, BankAccount $source): string
    {
        if (! in_array($schema, self::SUPPORTED_SCHEMAS, true)) {
            throw new \InvalidArgumentException("Unsupported direct debit PAIN schema: {$schema}");
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS('urn:iso:std:iso:20022:tech:xsd:'.$schema, 'Document');
        $document->appendChild($root);
        $initiation = $this->append($root, 'CstmrDrctDbtInitn');

        $groupHeader = $this->append($initiation, 'GrpHdr');
        $this->append($groupHeader, 'MsgId', (string) $debit->sepa_message_id);
        $this->append($groupHeader, 'CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $this->append($groupHeader, 'NbOfTxs', '1');
        $this->append($groupHeader, 'CtrlSum', number_format((float) $debit->amount, 2, '.', ''));
        $initiatingParty = $this->append($groupHeader, 'InitgPty');
        $this->append($initiatingParty, 'Nm', (string) $debit->creditor_name);

        $payment = $this->append($initiation, 'PmtInf');
        $this->append($payment, 'PmtInfId', (string) $debit->payment_information_id);
        $this->append($payment, 'PmtMtd', 'DD');
        $this->append($payment, 'NbOfTxs', '1');
        $this->append($payment, 'CtrlSum', number_format((float) $debit->amount, 2, '.', ''));

        $type = $this->append($payment, 'PmtTpInf');
        $serviceLevel = $this->append($type, 'SvcLvl');
        $this->append($serviceLevel, 'Cd', 'SEPA');
        $localInstrument = $this->append($type, 'LclInstrm');
        $this->append($localInstrument, 'Cd', (string) $debit->scheme->value);
        $this->append($type, 'SeqTp', (string) $debit->sequence_type->value);
        $this->append($payment, 'ReqdColltnDt', $debit->requested_collection_date?->format('Y-m-d') ?? '');

        $creditor = $this->append($payment, 'Cdtr');
        $this->append($creditor, 'Nm', (string) $debit->creditor_name);
        $this->appendAddress(
            $creditor,
            $debit->creditor_street,
            $debit->creditor_building_number,
            $debit->creditor_postal_code,
            $debit->creditor_city,
            $debit->creditor_country,
        );

        $creditorAccount = $this->append($payment, 'CdtrAcct');
        $creditorAccountId = $this->append($creditorAccount, 'Id');
        $this->append($creditorAccountId, 'IBAN', (string) $source->iban);
        $this->appendAgent($payment, 'CdtrAgt', $source->bic, $schema);
        $this->append($payment, 'ChrgBr', 'SLEV');

        $schemeId = $this->append($payment, 'CdtrSchmeId');
        $id = $this->append($schemeId, 'Id');
        $privateId = $this->append($id, 'PrvtId');
        $other = $this->append($privateId, 'Othr');
        $this->append($other, 'Id', (string) $debit->creditor_identifier);
        $schemeName = $this->append($other, 'SchmeNm');
        $this->append($schemeName, 'Prtry', 'SEPA');

        $transaction = $this->append($payment, 'DrctDbtTxInf');
        $paymentId = $this->append($transaction, 'PmtId');
        $this->append($paymentId, 'EndToEndId', (string) ($debit->end_to_end_id ?: 'NOTPROVIDED'));
        $amount = $this->append($transaction, 'InstdAmt', number_format((float) $debit->amount, 2, '.', ''));
        $amount->setAttribute('Ccy', 'EUR');

        $directDebitTransaction = $this->append($transaction, 'DrctDbtTx');
        $mandate = $this->append($directDebitTransaction, 'MndtRltdInf');
        $this->append($mandate, 'MndtId', (string) $debit->mandate_id_snapshot);
        $this->append($mandate, 'DtOfSgntr', $debit->mandate_signed_on?->format('Y-m-d') ?? '');
        $this->append($mandate, 'AmdmntInd', 'false');

        $this->appendAgent($transaction, 'DbtrAgt', $debit->debtor_bic, $schema);
        $debtor = $this->append($transaction, 'Dbtr');
        $this->append($debtor, 'Nm', (string) $debit->debtor_name);
        $this->appendAddress(
            $debtor,
            $debit->debtor_street,
            $debit->debtor_building_number,
            $debit->debtor_postal_code,
            $debit->debtor_city,
            $debit->debtor_country,
        );
        $debtorAccount = $this->append($transaction, 'DbtrAcct');
        $debtorAccountId = $this->append($debtorAccount, 'Id');
        $this->append($debtorAccountId, 'IBAN', (string) $debit->debtor_iban);

        if (filled($debit->purpose)) {
            $remittance = $this->append($transaction, 'RmtInf');
            $this->append($remittance, 'Ustrd', (string) $debit->purpose);
        }

        $xml = $document->saveXML();
        if (! is_string($xml)) {
            throw new \RuntimeException('Could not serialize SEPA direct debit XML.');
        }

        return $xml;
    }

    private function append(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $parent->ownerDocument->createElement($name);
        if ($value !== null) {
            $element->appendChild($parent->ownerDocument->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    private function appendAgent(DOMElement $parent, string $name, ?string $bic, string $schema): void
    {
        $agent = $this->append($parent, $name);
        $institution = $this->append($agent, 'FinInstnId');

        if (filled($bic)) {
            $this->append($institution, $schema === 'pain.008.001.08' ? 'BICFI' : 'BIC', (string) $bic);

            return;
        }

        if ($schema === 'pain.008.001.08') {
            $other = $this->append($institution, 'Othr');
            $this->append($other, 'Id', 'NOTPROVIDED');
        }
    }

    private function appendAddress(
        DOMElement $party,
        ?string $street,
        ?string $buildingNumber,
        ?string $postalCode,
        ?string $city,
        ?string $country,
    ): void {
        if (! filled($street) && ! filled($postalCode) && ! filled($city) && ! filled($country)) {
            return;
        }

        $address = $this->append($party, 'PstlAdr');
        if (filled($street)) {
            $this->append($address, 'StrtNm', (string) $street);
        }
        if (filled($buildingNumber)) {
            $this->append($address, 'BldgNb', (string) $buildingNumber);
        }
        if (filled($postalCode)) {
            $this->append($address, 'PstCd', (string) $postalCode);
        }
        if (filled($city)) {
            $this->append($address, 'TwnNm', (string) $city);
        }
        if (filled($country)) {
            $this->append($address, 'Ctry', strtoupper((string) $country));
        }
    }
}
