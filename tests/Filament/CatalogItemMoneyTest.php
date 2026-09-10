<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\CreateCatalogItem;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\EditCatalogItem;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CatalogItemMoneyTest extends TestCase
{
    #[Test]
    public function catalog_prices_are_entered_as_major_units_and_stored_as_minor_units(): void
    {
        $entity = $this->makeEntity();

        $created = (new class extends CreateCatalogItem
        {
            /** @param array<string, mixed> $data */
            public function normalizeForCreate(array $data): array
            {
                return $this->mutateFormDataBeforeCreate($data);
            }
        })->normalizeForCreate([
            'default_unit_price' => '12.34',
            'purchase_price' => '8.01',
            'currency' => 'EUR',
        ]);

        $this->assertSame($entity->getKey(), $created['legal_entity_id']);
        $this->assertSame(1234, $created['default_unit_price_minor']);
        $this->assertSame(801, $created['purchase_price_minor']);
        $this->assertArrayNotHasKey('purchase_price', $created);
        $this->assertArrayNotHasKey('default_unit_price', $created);

        $page = new class extends EditCatalogItem
        {
            /** @param array<string, mixed> $data */
            public function normalizeForFill(array $data): array
            {
                return $this->mutateFormDataBeforeFill($data);
            }

            /** @param array<string, mixed> $data */
            public function normalizeForSave(array $data): array
            {
                return $this->mutateFormDataBeforeSave($data);
            }
        };

        $filled = $page->normalizeForFill([
            'default_unit_price_minor' => 1234,
            'purchase_price_minor' => 801,
            'currency' => 'EUR',
        ]);
        $this->assertSame('12.34', $filled['default_unit_price']);
        $this->assertSame('8.01', $filled['purchase_price']);

        $saved = $page->normalizeForSave([
            'default_unit_price' => '9.87',
            'purchase_price' => '',
            'currency' => 'EUR',
        ]);
        $this->assertSame(987, $saved['default_unit_price_minor']);
        $this->assertNull($saved['purchase_price_minor']);
        $this->assertArrayNotHasKey('default_unit_price', $saved);
    }
}
