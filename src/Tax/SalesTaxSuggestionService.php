<?php

namespace FilamentAccounting\Tax;

use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Enums\PartyKind;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Services\ResolveTaxRuleVersion;
use FilamentAccounting\Tax\Data\SalesTaxSuggestion;

final class SalesTaxSuggestionService
{
    /** @var list<string> */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    public function __construct(private readonly ResolveTaxRuleVersion $taxRules) {}

    public function suggest(
        LegalEntity $entity,
        Party $customer,
        CatalogItemType|string $itemType,
        string $date,
        ?string $itemTaxCode = null,
        ?bool $businessCustomer = null,
    ): SalesTaxSuggestion {
        $companyCountry = strtoupper((string) $entity->country_code);
        $customerCountry = $this->customerCountry($customer);
        $itemType = is_string($itemType) ? CatalogItemType::from($itemType) : $itemType;
        $hasVatId = $this->hasVatId($customer, $customerCountry);
        $businessCustomer ??= $customer->kind === PartyKind::Organization || $hasVatId;

        if ($companyCountry !== 'DE') {
            return $this->result(
                $entity,
                'DE-19',
                $date,
                __('filament-accounting::tax_suggestions.unsupported_company_country'),
                true,
            );
        }

        if ($customerCountry === 'DE') {
            $taxCode = in_array($itemTaxCode, ['DE-19', 'DE-7', 'DE-0'], true) ? $itemTaxCode : 'DE-19';

            return $this->result(
                $entity,
                $taxCode,
                $date,
                __('filament-accounting::tax_suggestions.domestic'),
                false,
            );
        }

        if (in_array($customerCountry, self::EU_COUNTRIES, true)) {
            if ($businessCustomer && $hasVatId) {
                return $this->result(
                    $entity,
                    $itemType === CatalogItemType::Service ? 'DE-RC' : 'DE-IG',
                    $date,
                    $itemType === CatalogItemType::Service
                        ? __('filament-accounting::tax_suggestions.eu_b2b_service')
                        : __('filament-accounting::tax_suggestions.eu_b2b_goods'),
                    false,
                );
            }

            return $this->result(
                $entity,
                'DE-19',
                $date,
                __('filament-accounting::tax_suggestions.eu_ambiguous'),
                true,
            );
        }

        if ($customerCountry !== '') {
            return $this->result(
                $entity,
                'DE-EXPORT',
                $date,
                $itemType === CatalogItemType::Product
                    ? __('filament-accounting::tax_suggestions.third_country_goods')
                    : __('filament-accounting::tax_suggestions.third_country_service'),
                true,
            );
        }

        return $this->result(
            $entity,
            'DE-19',
            $date,
            __('filament-accounting::tax_suggestions.missing_customer_country'),
            true,
        );
    }

    private function customerCountry(Party $customer): string
    {
        $customer->loadMissing('addresses');
        $address = $customer->addresses->firstWhere('is_primary', true) ?? $customer->addresses->first();

        return strtoupper((string) ($address instanceof PartyAddress ? $address->country_code : $customer->country_code));
    }

    private function hasVatId(Party $customer, string $country): bool
    {
        $customer->loadMissing('taxIds');

        return $customer->taxIds->contains(function (PartyTaxId $taxId) use ($country): bool {
            if ($taxId->type !== 'vat' || ! filled($taxId->number)) {
                return false;
            }

            $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $taxId->number));

            return strlen($normalized) >= 8
                && ($country === '' || str_starts_with($normalized, $country));
        });
    }

    private function result(
        LegalEntity $entity,
        string $taxCode,
        string $date,
        string $explanation,
        bool $requiresConfirmation,
    ): SalesTaxSuggestion {
        $version = $this->taxRules->handle($entity, $taxCode, $date);

        return new SalesTaxSuggestion(
            $taxCode,
            (int) $version->rate_bp,
            $explanation,
            $requiresConfirmation,
        );
    }
}
