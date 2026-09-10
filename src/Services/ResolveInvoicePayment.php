<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Enums\InvoicePaymentMethod;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Document;

final class ResolveInvoicePayment
{
    public static function method(mixed $value): InvoicePaymentMethod
    {
        return ($value instanceof InvoicePaymentMethod ? $value : InvoicePaymentMethod::tryFrom((string) $value))
            ?? throw new DocumentException(__('filament-accounting::invoice.invalid_payment_method'));
    }

    /** @return array<string, mixed> */
    public function snapshot(Document $document, bool $requireMandate = true): array
    {
        $method = $document->payment_method ?? InvoicePaymentMethod::CreditTransfer;
        $snapshot = ['method' => $method->value];
        if ($method !== InvoicePaymentMethod::DirectDebit) {
            return $snapshot;
        }
        if (! $requireMandate && $document->direct_debit_mandate_id === null) {
            return $snapshot;
        }
        $mandate = DirectDebitMandate::query()->with('creditorProfile')
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('party_id', $document->party_id)
            ->where('status', DirectDebitMandateStatus::Active)
            ->whereDate('signed_on', '<=', $document->issue_date)
            ->find($document->direct_debit_mandate_id);
        if (! $mandate instanceof DirectDebitMandate
            || (string) $mandate->creditorProfile->legal_entity_id !== (string) $document->legal_entity_id) {
            throw new DocumentException(__('filament-accounting::invoice.mandate_required'));
        }

        return $snapshot + [
            'mandate_reference' => $mandate->reference,
            'creditor_identifier' => $mandate->creditorProfile->creditor_identifier,
            'debtor_iban' => $mandate->debtor_iban,
            'debtor_name' => $mandate->debtor_name,
        ];
    }
}
