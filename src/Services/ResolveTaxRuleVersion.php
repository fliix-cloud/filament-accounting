<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Models\TaxRuleVersion;

final class ResolveTaxRuleVersion
{
    public function handle(LegalEntity $entity, mixed $code, string $date): TaxRuleVersion
    {
        if (! is_string($code) || blank($code)) {
            throw new DocumentException(__('filament-accounting::errors.tax_code_required'));
        }

        $taxCode = TaxCode::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('code', trim($code))
            ->where('is_active', true)
            ->first();

        if (! $taxCode instanceof TaxCode) {
            throw new DocumentException(__('filament-accounting::errors.tax_code_unknown', ['code' => $code]));
        }

        $version = $taxCode->versionOn($date);

        if (! $version instanceof TaxRuleVersion) {
            throw new DocumentException(__('filament-accounting::errors.tax_rule_missing_for_date', [
                'code' => $taxCode->code,
                'date' => $date,
            ]));
        }

        return $version;
    }
}
