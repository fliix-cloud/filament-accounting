<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Support\SepaPainCreditTransfer;
use FilamentAccounting\Banking\FinTs\Support\SepaPainDirectDebit;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;

class SepaXmlService
{
    public function __construct(
        private readonly SepaPainDirectDebit $directDebit,
    ) {}

    /**
     * @param  list<string>  $schemas
     */
    public function transferXml(BankTransfer $transfer, BankAccount $source, array $schemas = []): string
    {
        if (! filled($source->account_holder_name)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.account_holder_name'));
        }

        $connection = $source->connection;
        $schema = $this->painInitiation($connection instanceof BankConnection ? $connection : null, $schemas, 'pain.001', 'pain.001.003.03', [
            'pain.001.001.09',
            'pain.001.001.03',
            'pain.001.003.03',
            'pain.001.002.03',
        ]);

        return (new SepaPainCreditTransfer)->toXml(
            $schema,
            (string) $transfer->uuid,
            (string) $transfer->idempotency_key,
            (string) $source->account_holder_name,
            (string) $source->iban,
            $source->bic,
            (string) $transfer->recipient_name,
            (string) $transfer->recipient_iban,
            $transfer->recipient_bic,
            (string) $transfer->amount,
            (string) ($transfer->currency ?: 'EUR'),
            $transfer->purpose,
            $transfer->end_to_end_id,
        );
    }

    /**
     * @param  list<string>  $schemas
     */
    public function directDebitXml(BankDirectDebit $debit, BankAccount $source, array $schemas = []): string
    {
        $connection = $source->connection;
        $schema = $this->directDebitPainSchema($connection instanceof BankConnection ? $connection : null, $schemas);

        if ($schema === 'pain.008.001.02' && (! filled($source->bic) || ! filled($debit->debtor_bic))) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.bic'));
        }

        return $this->directDebit->toXml($schema, $debit, $source);
    }

    /**
     * @param  list<string>  $schemas
     */
    private function directDebitPainSchema(?BankConnection $connection, array $schemas): string
    {
        if ($schemas === []) {
            $stored = $connection?->capabilities['sepa_pain_schemas'] ?? [];
            $schemas = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
        }

        if ($schemas === []) {
            // Current EPC customer-to-PSP schema. SendSEPADirectDebit performs the
            // final BPD/HIDXES compatibility check before the order is sent.
            return 'pain.008.001.08';
        }

        foreach (SepaPainDirectDebit::SUPPORTED_SCHEMAS as $supported) {
            foreach ($schemas as $advertised) {
                if (str_contains($advertised, $supported)) {
                    return $supported;
                }
            }
        }

        throw new FintsValidationException(__('filament-accounting::banking/fints/errors.unsupported_direct_debit_pain'));
    }

    /**
     * @param  list<string>  $schemas
     * @param  list<string>  $preferred
     */
    private function painInitiation(?BankConnection $connection, array $schemas, string $family, string $fallback, array $preferred): string
    {
        if ($schemas === []) {
            $stored = $connection?->capabilities['sepa_pain_schemas'] ?? [];
            $schemas = is_array($stored) ? $stored : [];
        }

        if ($schemas === []) {
            return $fallback;
        }

        foreach ($preferred as $short) {
            foreach ($schemas as $urn) {
                if (is_string($urn) && str_contains($urn, $short)) {
                    return $short;
                }
            }
        }

        foreach ($schemas as $urn) {
            if (is_string($urn) && preg_match('/('.preg_quote($family, '/').'\.\d+\.\d+)/', $urn, $matches) === 1) {
                return $matches[1];
            }
        }

        return $fallback;
    }
}
