<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\AuditEventResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
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
            AccountingNavigation::LEDGER,
            AccountingNavigation::ADMINISTRATION,
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
                BankStatementLineResource::class,
                ReconciliationPage::class,
            ],
            AccountingNavigation::LEDGER => [
                JournalEntryResource::class,
                LedgerAccountResource::class,
                PostingRuleResource::class,
            ],
            AccountingNavigation::ADMINISTRATION => [
                LegalEntityResource::class,
                AuditEventResource::class,
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
}
