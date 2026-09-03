<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Resources\SupplierResource;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AccountingNavigationTest extends TestCase
{
    #[Test]
    public function workflow_sections_are_registered_as_stable_parent_items(): void
    {
        $items = collect(AccountingNavigation::items())->keyBy(
            fn ($item): string => $item->getKey(),
        );

        $this->assertSame([
            AccountingNavigation::SALES,
            AccountingNavigation::PURCHASES,
            AccountingNavigation::BANKING,
            AccountingNavigation::REPORTS,
            AccountingNavigation::SETTINGS,
        ], $items->keys()->all());

        foreach ($items as $item) {
            $this->assertSame(__('filament-accounting::navigation.group'), $item->getGroup());
            $this->assertNotNull($item->getIcon());
            $this->assertNull($item->getUrl());
        }
    }

    #[Test]
    public function resources_are_grouped_by_accounting_workflow(): void
    {
        $expected = [
            AccountingNavigation::SALES => [
                SalesInvoiceResource::class,
                CustomerResource::class,
                CatalogItemResource::class,
            ],
            AccountingNavigation::PURCHASES => [
                PurchaseInvoiceResource::class,
                SupplierResource::class,
            ],
            AccountingNavigation::BANKING => [
                BankConnectionResource::class,
                AccountingBankAccountResource::class,
                BankStatementLineResource::class,
                BankTransferResource::class,
                DirectDebitCreditorProfileResource::class,
                DirectDebitMandateResource::class,
                BankDirectDebitResource::class,
                ReconciliationPage::class,
            ],
            AccountingNavigation::REPORTS => [
                JournalEntryResource::class,
            ],
            AccountingNavigation::SETTINGS => [
                LegalEntityResource::class,
            ],
        ];

        foreach ($expected as $parent => $children) {
            foreach ($children as $child) {
                $this->assertSame($parent, $child::getNavigationParentItem());
                $this->assertSame(
                    __('filament-accounting::navigation.group'),
                    $child::getNavigationGroup(),
                );
            }
        }
    }

    #[Test]
    public function all_fints_management_resources_register_navigation(): void
    {
        foreach ([
            BankConnectionResource::class,
            BankTransferResource::class,
            DirectDebitCreditorProfileResource::class,
            DirectDebitMandateResource::class,
            BankDirectDebitResource::class,
        ] as $resource) {
            $this->assertTrue($resource::shouldRegisterNavigation());
        }
    }
}
