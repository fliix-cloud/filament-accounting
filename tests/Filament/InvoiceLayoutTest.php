<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;
use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\EditPurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\EditSalesInvoice;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class InvoiceLayoutTest extends TestCase
{
    #[Test]
    public function invoice_line_repeaters_use_the_full_width_with_a_twelve_column_item_grid(): void
    {
        foreach ([
            [PurchaseInvoiceResource::class, new EditPurchaseInvoice],
            [SalesInvoiceResource::class, new EditSalesInvoice],
        ] as [$resource, $page]) {
            $lines = $resource::form(Schema::make($page))->getComponent('lines');

            $this->assertInstanceOf(Repeater::class, $lines);
            $this->assertSame('full', $lines->getColumnSpan('default'));
            $this->assertSame(12, $lines->getChildSchema()->getColumns('lg'));
        }
    }

    #[Test]
    public function purchase_invoice_view_shows_totals_lines_original_filenames_and_short_download_labels(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, [
            'is_customer' => false,
            'is_supplier' => true,
            'legal_name' => 'Greenmark IT GmbH',
        ]);

        $document = Document::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => DocumentType::PurchaseInvoice,
            'direction' => DocumentDirection::Incoming,
            'supplier_invoice_number' => 'R2026072415784',
            'party_id' => $supplier->getKey(),
            'issue_date' => '2026-07-31',
            'currency' => 'EUR',
            'net_minor' => 504,
            'tax_minor' => 96,
            'gross_minor' => 600,
        ]);
        $document->lines()->create([
            'position' => 1,
            'description' => 'hautpflegen.de - Verlaengerung 12 Monate',
            'quantity' => '1',
            'unit' => 'H87',
            'unit_price_minor' => 252,
            'net_minor' => 252,
            'tax_rate_bp' => 1900,
            'tax_minor' => 48,
            'gross_minor' => 300,
        ]);
        $this->attach($document, '4281_Beleg_2026.07.31.pdf', 'application/pdf');
        $this->attach($document, '4281_Beleg_2026.07.31-embedded.xml', 'application/xml');

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $document->getRouteKey()])
            ->assertOk()
            ->assertSee('Summen')
            ->assertSee('5,04')
            ->assertSee('6,00')
            ->assertSee('hautpflegen.de')
            ->assertSee('4281_Beleg_2026.07.31.pdf')
            ->assertSee('PDF')
            ->assertSee('XML');
    }

    private function attach(Document $document, string $filename, string $mimeType): void
    {
        Attachment::query()->create([
            'legal_entity_id' => $document->legal_entity_id,
            'attachable_type' => $document->getMorphClass(),
            'attachable_id' => $document->getKey(),
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size' => 1,
            'sha256' => hash('sha256', $filename),
            'disk' => 'local',
            'path' => 'test/'.$filename,
            'source_type' => str_contains($mimeType, 'pdf') ? 'original_invoice' : 'embedded_e_invoice',
        ]);
    }
}
