<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\CreateSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\EditSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ViewSalesInvoice;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class SalesInvoiceEditingTest extends TestCase
{
    #[Test]
    public function dates_default_to_today_and_follow_customer_terms_with_manual_override(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity, ['payment_terms_days' => 30]);
        $immediate = $this->makeParty($entity, ['payment_terms_days' => 0]);
        $newCustomer = Party::query()->create([
            'legal_entity_id' => $entity->getKey(), 'legal_name' => 'New customer', 'is_customer' => true,
        ]);
        $this->assertSame(7, $newCustomer->fresh()->payment_terms_days);
        $this->assertSame(30, $customer->fresh()->payment_terms_days);

        Livewire::test(CreateSalesInvoice::class)
            ->assertFormSet(['issue_date' => '2026-09-10', 'supply_date' => '2026-09-10', 'due_date' => '2026-09-17'])
            ->set('data.party_id', $customer->getKey())
            ->assertFormSet(['due_date' => '2026-10-10'])
            ->set('data.issue_date', '2026-09-20')
            ->assertFormSet(['due_date' => '2026-10-20'])
            ->set('data.due_date', '2026-12-01')
            ->assertFormSet(['due_date' => '2026-12-01'])
            ->set('data.party_id', $immediate->getKey())
            ->assertFormSet(['due_date' => '2026-09-20']);
    }

    #[Test]
    public function comma_inputs_are_saved_exactly_and_preview_does_not_persist_a_document(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $payload = [
            'party_id' => $customer->getKey(), 'issue_date' => '2026-09-10',
            'supply_date' => '2026-09-10', 'due_date' => '2026-10-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '2,5', 'unit_price' => '32,99', 'tax_code' => 'DE-19']],
        ];
        $page = Livewire::test(CreateSalesInvoice::class)->fillForm($payload)->assertSee('82,48');
        $events = AuditEvent::query()->count();
        $page->callAction('previewPdf')->assertHasNoActionErrors()->assertFileDownloaded('invoice-preview.pdf');
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DocumentLine::query()->count());
        $this->assertSame($events, AuditEvent::query()->count());

        $page->call('create')->assertHasNoFormErrors();
        $draft = Document::query()->sole();
        $this->assertSame(3299, $draft->lines->sole()->unit_price_minor);
        $this->assertSame(8248, $draft->net_minor);
        $this->assertSame('2026-10-10', $draft->due_date->toDateString());

        Livewire::test(EditSalesInvoice::class, ['record' => $draft->getRouteKey()])
            ->assertFormSet(['due_date' => '2026-10-10'])
            ->fillForm(['lines' => [['description' => 'Consulting', 'quantity' => '2.5', 'unit_price' => '32.99', 'tax_code' => 'DE-19']]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(8248, $draft->fresh()->net_minor);
    }

    #[Test]
    public function view_offers_edit_and_delete_for_drafts_and_reasoned_correction_for_issued_invoices(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $issuer = app(IssueSalesInvoice::class);
        $draft = $issuer->createDraft($entity, [
            'party_id' => $customer->getKey(), 'issue_date' => '2026-09-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '32.99', 'tax_code' => 'DE-19']],
        ]);
        Livewire::test(ViewSalesInvoice::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('edit')->assertActionVisible('delete');
        $issued = $issuer->issue($draft);
        Livewire::test(ViewSalesInvoice::class, ['record' => $issued->getRouteKey()])
            ->assertActionVisible('edit')->assertActionHidden('delete')->assertActionVisible('generateArtifacts');

        $page = Livewire::test(EditSalesInvoice::class, ['record' => $issued->getRouteKey()]);
        $page->call('save')->assertHasFormErrors(['correction_reason' => 'required']);
        $page->fillForm(['correction_reason' => 'Wrong quantity', 'lines' => [
            ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '32,99', 'tax_code' => 'DE-19'],
        ]])->call('save')->assertHasNoFormErrors();

        $correction = Document::query()->where('corrected_document_id', $issued->getKey())->sole();
        $page->assertRedirect(SalesInvoiceResource::getUrl('view', ['record' => $correction]));
        $this->assertSame(3299, $issued->fresh()->net_minor);
        $this->assertSame(6598, $correction->net_minor);
        $this->assertSame('Wrong quantity', AuditEvent::query()->where('operation', 'document.correction_created')->sole()->reason);

        $issuer->deleteDraft($correction);
        $this->assertNull($correction->fresh());
        $this->assertTrue(SalesInvoiceResource::canEdit($issued->fresh()));
    }

    #[Test]
    public function draft_can_be_deleted_from_its_detail_page(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $draft = app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $customer->getKey(), 'issue_date' => '2026-09-10', 'currency' => 'EUR',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '32.99', 'tax_code' => 'DE-19']],
        ]);
        Livewire::test(ViewSalesInvoice::class, ['record' => $draft->getRouteKey()])
            ->callAction('delete')->assertHasNoActionErrors()
            ->assertRedirect(SalesInvoiceResource::getUrl('index'));
        $this->assertNull($draft->fresh());
    }
}
