<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\CreateSalesInvoice;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class SalesInvoiceTaxWarningTest extends TestCase
{
    #[Test]
    public function catalog_tax_follows_customer_country_and_preserves_custom_position_values(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $domestic = $this->makeParty($entity);
        $french = $this->makeParty($entity, ['country_code' => 'FR']);
        $french->addresses()->create(['country_code' => 'DE', 'is_primary' => true]);
        $french->taxIds()->create(['type' => 'vat', 'number' => 'FR40303265045', 'country_code' => 'FR']);
        $item = CatalogItem::query()->create([
            'legal_entity_id' => $entity->id, 'sku' => '10034', 'name' => 'Remote Wartung',
            'description' => '<p>Wartung</p>', 'type' => 'service', 'unit' => 'HUR',
            'default_quantity' => '1', 'default_unit_price_minor' => 10000,
            'currency' => 'EUR', 'default_tax_code' => 'DE-19', 'is_active' => true,
        ]);
        $page = Livewire::test(CreateSalesInvoice::class)->set('data.party_id', $french->id);
        $key = array_key_first($page->get('data.lines'));
        $line = "data.lines.{$key}";
        $page->set("{$line}.catalog_item_id", $item->id)
            ->assertSet("{$line}.tax_code", 'DE-RC')
            ->set("{$line}.description", '<p>Wartung Server September</p>')
            ->set("{$line}.unit_price", '85.00');
        $description = $page->get("{$line}.description");
        $page->set('data.party_id', $domestic->id)
            ->assertSet("{$line}.tax_code", 'DE-19')
            ->assertSet("{$line}.description", $description)
            ->assertSet("{$line}.unit_price", '85.00')
            ->set('data.party_id', $french->id)
            ->assertSet("{$line}.tax_code", 'DE-RC')
            ->set("{$line}.tax_code", 'DE-19')
            ->set("{$line}.quantity", '2')
            ->assertSet("{$line}.tax_code", 'DE-19');
    }

    #[Test]
    public function warnings_follow_customer_tax_and_date_changes_and_reset_confirmation(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $domestic = $this->makeParty($entity);
        $usa = $this->makeParty($entity, ['country_code' => 'US']);
        $usa->addresses()->create([
            'type' => 'billing',
            'country_code' => 'US',
            'is_primary' => true,
        ]);
        $item = CatalogItem::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => 'service',
            'name' => 'Remote IT Support',
            'description' => '<p>Remote support</p>',
            'unit' => 'hour',
            'default_quantity' => '1',
            'default_unit_price_minor' => 10000,
            'currency' => 'EUR',
            'default_tax_code' => 'DE-19',
            'is_active' => true,
        ]);

        $page = Livewire::test(CreateSalesInvoice::class)
            ->assertOk()
            ->assertDontSee('Kunde und Artikel auswählen')
            ->assertDontSee('Positions-Warnung');
        $key = array_key_first($page->get('data.lines'));
        $line = "data.lines.{$key}";

        // Choose the item before the customer: this previously left the hint stale.
        $page->set("{$line}.catalog_item_id", $item->getKey())
            ->set('data.issue_date', '2026-09-10')
            ->set('data.party_id', $domestic->getKey())
            ->assertDontSee('Positions-Warnung')
            ->set('data.party_id', $usa->getKey())
            ->assertSee('Positions-Warnung')
            ->assertDontSee('Die gewählte Steuerbehandlung DE-19 weicht')
            ->assertSet("{$line}.tax_code", 'DE-EXPORT')
            ->set("{$line}.tax_code", 'DE-EXPORT')
            ->assertDontSee('Die gewählte Steuerbehandlung')
            ->assertSee('Leistungsort')
            ->set("{$line}.tax_confirmed", true)
            ->set('data.supply_date', '2026-09-09')
            ->assertSet("{$line}.tax_confirmed", false)
            ->set("{$line}.tax_confirmed", true)
            ->set("{$line}.tax_code", 'DE-19')
            ->assertSet("{$line}.tax_confirmed", false)
            ->assertSee('Die gewählte Steuerbehandlung DE-19 weicht')
            ->set('data.party_id', $domestic->getKey())
            ->assertDontSee('Positions-Warnung')
            ->set("{$line}.tax_code", 'DE-EXPORT')
            ->assertSee('Die gewählte Steuerbehandlung DE-EXPORT weicht')
            ->set("{$line}.catalog_item_id", null)
            ->assertDontSee('Positions-Warnung')
            ->set('data.party_id', $usa->getKey())
            ->set("{$line}.catalog_item_id", $item->getKey())
            ->assertSet("{$line}.tax_code", 'DE-EXPORT')
            ->assertSee('Leistungsort');
    }
}
