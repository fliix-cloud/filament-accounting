<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Models\DocumentSequence;
use FilamentAccounting\Models\LegalEntity;

final class AllocateDocumentNumber
{
    public function __construct(
        private readonly ResolveAccountingPeriod $periods,
    ) {}

    public function next(LegalEntity $entity, DocumentType $type, string $date): string
    {
        [$fiscalYear] = $this->periods->fiscalYear($entity, $date);
        $prefix = $this->prefix($type, $fiscalYear);

        $sequence = DocumentSequence::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('document_type', $type->value)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->first();

        if (! $sequence instanceof DocumentSequence) {
            $sequence = new DocumentSequence;
            $sequence->fill([
                'legal_entity_id' => $entity->getKey(),
                'document_type' => $type,
                'fiscal_year' => $fiscalYear,
                'next_number' => 1,
                'prefix' => $prefix,
            ]);
            $sequence->save();
            $sequence = DocumentSequence::query()
                ->whereKey($sequence->getKey())
                ->lockForUpdate()
                ->firstOrFail();
        }

        $number = (int) $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return sprintf('%s-%06d', $sequence->prefix ?: $prefix, $number);
    }

    private function prefix(DocumentType $type, int $fiscalYear): string
    {
        $code = match ($type) {
            DocumentType::SalesInvoice => 'RE',
            DocumentType::PurchaseInvoice => 'ER',
            DocumentType::SalesCreditNote => 'GS',
            DocumentType::PurchaseCreditNote => 'EG',
        };

        return $code.$fiscalYear;
    }
}
