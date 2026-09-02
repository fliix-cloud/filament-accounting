<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
use FilamentAccounting\Filament\Resources\TaxCodeResource;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Ownership\ConfiguredLegalEntityResolver;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigurationResourceScopeTest extends TestCase
{
    #[Test]
    public function chart_tax_posting_rule_and_catalog_resources_only_query_the_current_entity(): void
    {
        $current = $this->makeEntity(['legal_name' => 'Current GmbH']);
        $other = $this->makeEntity(['legal_name' => 'Other GmbH']);
        foreach ([$current, $other] as $index => $entity) {
            CatalogItem::query()->create([
                'legal_entity_id' => $entity->getKey(),
                'sku' => 'SCOPE-'.$index,
                'type' => 'service',
                'name' => 'Scoped item '.$index,
                'unit' => 'unit',
                'default_quantity' => '1',
                'default_unit_price_minor' => 100,
                'currency' => 'EUR',
                'default_tax_code' => 'DE-19',
                'is_active' => true,
            ]);
        }

        app(ConfiguredLegalEntityResolver::class)->bind($current);
        config()->set('filament-accounting.ownership.legal_entity_id', $current->getKey());

        foreach ([
            CatalogItemResource::class,
            LedgerAccountResource::class,
            PostingRuleResource::class,
            TaxCodeResource::class,
        ] as $resource) {
            $entityIds = $resource::getEloquentQuery()
                ->pluck('legal_entity_id')
                ->unique()
                ->values()
                ->all();

            $this->assertSame([$current->getKey()], $entityIds, $resource);
        }
    }
}
