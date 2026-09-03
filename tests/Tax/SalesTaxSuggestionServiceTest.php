<?php

namespace FilamentAccounting\Tests\Tax;

use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Enums\PartyKind;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tax\SalesTaxSuggestionService;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SalesTaxSuggestionServiceTest extends TestCase
{
    #[Test]
    public function a_domestic_invoice_uses_the_item_class_and_historical_rate(): void
    {
        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity, ['country_code' => 'DE']);

        $suggestion = app(SalesTaxSuggestionService::class)->suggest(
            $entity,
            $customer,
            CatalogItemType::Service,
            '2020-08-01',
            'DE-19',
        );

        $this->assertSame('DE-19', $suggestion->taxCode);
        $this->assertSame(1600, $suggestion->rateBp);
        $this->assertFalse($suggestion->requiresConfirmation);
        $this->assertNotSame('', $suggestion->explanation);
    }

    #[Test]
    public function eu_b2b_services_and_goods_receive_explained_zero_percent_recommendations(): void
    {
        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity, [
            'country_code' => 'FR',
            'kind' => PartyKind::Organization,
        ]);
        PartyTaxId::query()->create([
            'party_id' => $customer->getKey(),
            'type' => 'vat',
            'number' => 'FR40303265045',
            'country_code' => 'FR',
        ]);
        $service = app(SalesTaxSuggestionService::class);

        $serviceSuggestion = $service->suggest($entity, $customer, CatalogItemType::Service, '2026-09-03');
        $goodsSuggestion = $service->suggest($entity, $customer, CatalogItemType::Product, '2026-09-03');

        $this->assertSame('DE-RC', $serviceSuggestion->taxCode);
        $this->assertSame('DE-IG', $goodsSuggestion->taxCode);
        $this->assertSame(0, $serviceSuggestion->rateBp);
        $this->assertSame(0, $goodsSuggestion->rateBp);
        $this->assertFalse($serviceSuggestion->requiresConfirmation);
        $this->assertFalse($goodsSuggestion->requiresConfirmation);
        $this->assertNotSame($serviceSuggestion->explanation, $goodsSuggestion->explanation);
    }

    #[Test]
    public function ambiguous_eu_and_third_country_cases_are_prefilled_but_require_confirmation(): void
    {
        $entity = $this->makeEntity();
        $euConsumer = $this->makeParty($entity, [
            'country_code' => 'NL',
            'kind' => PartyKind::Person,
        ]);
        $thirdCountryCustomer = $this->makeParty($entity, [
            'country_code' => 'CH',
            'kind' => PartyKind::Organization,
        ]);
        $service = app(SalesTaxSuggestionService::class);

        $euSuggestion = $service->suggest($entity, $euConsumer, CatalogItemType::Product, '2026-09-03');
        $thirdCountrySuggestion = $service->suggest($entity, $thirdCountryCustomer, CatalogItemType::Service, '2026-09-03');

        $this->assertSame('DE-19', $euSuggestion->taxCode);
        $this->assertTrue($euSuggestion->requiresConfirmation);
        $this->assertSame('DE-EXPORT', $thirdCountrySuggestion->taxCode);
        $this->assertTrue($thirdCountrySuggestion->requiresConfirmation);
        $this->assertNotSame('', $euSuggestion->explanation);
        $this->assertNotSame('', $thirdCountrySuggestion->explanation);
    }

    #[Test]
    public function an_eu_b2b_service_prefills_reverse_charge_in_the_invoice_workflow(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity, [
            'country_code' => 'FR',
            'kind' => PartyKind::Organization,
        ]);
        PartyTaxId::query()->create([
            'party_id' => $customer->getKey(),
            'type' => 'vat',
            'number' => 'FR40303265045',
            'country_code' => 'FR',
        ]);
        $service = CatalogItem::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => CatalogItemType::Service,
            'name' => 'Consulting',
            'unit' => 'hour',
            'default_quantity' => '1',
            'default_unit_price_minor' => 10000,
            'currency' => 'EUR',
            'default_account_role' => 'revenue',
            'default_tax_code' => 'DE-19',
            'is_active' => true,
        ]);

        $invoice = app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-09-03',
            'currency' => 'EUR',
            'lines' => [[
                'catalog_item_id' => $service->getKey(),
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 10000,
            ]],
        ]);

        $this->assertSame('DE-RC', $invoice->lines->firstOrFail()->tax_code);
        $this->assertSame(0, $invoice->tax_minor);
    }

    #[Test]
    public function a_third_country_suggestion_must_be_confirmed_before_the_draft_is_saved(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity, [
            'country_code' => 'CH',
            'kind' => PartyKind::Organization,
        ]);
        $product = CatalogItem::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => CatalogItemType::Product,
            'name' => 'Export goods',
            'unit' => 'piece',
            'default_quantity' => '1',
            'default_unit_price_minor' => 10000,
            'currency' => 'EUR',
            'default_account_role' => 'revenue',
            'default_tax_code' => 'DE-19',
            'is_active' => true,
        ]);
        $payload = [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-09-03',
            'currency' => 'EUR',
            'lines' => [[
                'catalog_item_id' => $product->getKey(),
                'description' => 'Export goods',
                'quantity' => '1',
                'unit_price_minor' => 10000,
            ]],
        ];

        try {
            app(IssueSalesInvoice::class)->createDraft($entity, $payload);
            $this->fail('An ambiguous tax suggestion was accepted without confirmation.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::errors.tax_suggestion_confirmation_required'), $exception->getMessage());
        }

        $payload['lines'][0]['tax_confirmed'] = true;
        $invoice = app(IssueSalesInvoice::class)->createDraft($entity, $payload);

        $this->assertSame('DE-EXPORT', $invoice->lines->firstOrFail()->tax_code);
        $this->assertSame(0, $invoice->tax_minor);
    }
}
