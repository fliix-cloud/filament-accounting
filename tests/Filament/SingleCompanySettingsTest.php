<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SingleCompanySettingsTest extends TestCase
{
    #[Test]
    public function the_instance_resolves_its_only_company_without_configuration(): void
    {
        $this->assertNull(app(SingleLegalEntityResolver::class)->resolve());

        $company = $this->makeEntity(['legal_name' => 'Single GmbH']);

        $this->assertTrue(app(SingleLegalEntityResolver::class)->resolve()?->is($company));
    }

    #[Test]
    public function company_settings_have_a_direct_route_and_no_list_or_edit_routes(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));

        $pages = LegalEntityResource::getPages();

        $this->assertSame(['index', 'create'], array_keys($pages));
        $this->assertStringEndsWith('/accounting/company-settings', LegalEntityResource::getUrl());
        $this->assertStringEndsWith('/accounting/company-settings/setup', LegalEntityResource::getUrl('create'));
    }

    #[Test]
    public function a_second_company_cannot_be_created_through_the_resource(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->makeUser());

        $this->assertTrue(LegalEntityResource::canCreate());

        $this->makeEntity();

        $this->assertFalse(LegalEntityResource::canCreate());
        $this->assertDatabaseCount((new LegalEntity)->getTable(), 1);
    }

    #[Test]
    public function the_company_settings_route_renders_the_only_company_form(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->makeUser());
        $this->makeEntity(['legal_name' => 'Settings GmbH']);

        $this->get(LegalEntityResource::getUrl())
            ->assertOk()
            ->assertSee('Settings GmbH');
    }

    #[Test]
    public function an_empty_instance_redirects_company_settings_to_initial_setup(): void
    {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $this->actingAs($this->makeUser());

        $this->get(LegalEntityResource::getUrl())
            ->assertRedirect(LegalEntityResource::getUrl('create'));
    }
}
