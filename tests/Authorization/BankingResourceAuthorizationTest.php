<?php

namespace FilamentAccounting\Tests\Authorization;

use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class BankingResourceAuthorizationTest extends TestCase
{
    protected function defineAccountingGates(): void {}

    #[Test]
    public function banking_resources_deny_panel_users_without_bank_gates(): void
    {
        $this->actingAs($this->makeUser());

        foreach ([
            BankTransferResource::class,
            BankDirectDebitResource::class,
            BankConnectionResource::class,
            DirectDebitMandateResource::class,
            DirectDebitCreditorProfileResource::class,
        ] as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource);
            $this->assertFalse($resource::canCreate(), $resource);
        }
    }

    #[Test]
    public function viewing_transfers_does_not_allow_creating_them(): void
    {
        $this->actingAs($this->makeUser());
        Gate::define('accounting.bank.view', fn (): bool => true);

        $this->assertTrue(BankTransferResource::canViewAny());
        $this->assertFalse(BankTransferResource::canCreate());
        $this->assertTrue(BankDirectDebitResource::canViewAny());
        $this->assertFalse(BankDirectDebitResource::canCreate());
        $this->assertFalse(BankConnectionResource::canViewAny());
    }

    #[Test]
    public function connection_management_is_required_for_settings_resources(): void
    {
        $this->actingAs($this->makeUser());
        Gate::define('accounting.bank.manage-connections', fn (): bool => true);

        $this->assertTrue(BankConnectionResource::canViewAny());
        $this->assertTrue(BankConnectionResource::canCreate());
        $this->assertTrue(DirectDebitMandateResource::canCreate());
        $this->assertTrue(DirectDebitCreditorProfileResource::canCreate());
        $this->assertFalse(BankTransferResource::canViewAny());
    }

    #[Test]
    public function sales_invoices_can_be_listed_with_view_permission(): void
    {
        $this->actingAs($this->makeUser());
        $this->assertFalse(SalesInvoiceResource::canViewAny());

        Gate::define('accounting.view', fn (): bool => true);
        $this->assertTrue(SalesInvoiceResource::canViewAny());
        $this->assertFalse(SalesInvoiceResource::canCreate());
    }

    #[Test]
    public function sales_invoices_can_be_listed_and_created_with_draft_permission(): void
    {
        $this->actingAs($this->makeUser());
        Gate::define('accounting.invoices.draft', fn (): bool => true);
        $this->assertTrue(SalesInvoiceResource::canViewAny());
        $this->assertTrue(SalesInvoiceResource::canCreate());
    }
}
