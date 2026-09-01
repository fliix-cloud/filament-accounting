<?php

namespace FilamentAccounting;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Colors\Color;
use FilamentAccounting\Contracts\BankFeedDriver;
use FilamentAccounting\Contracts\BankFeedDriverRegistry;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Pages\AccountingOverview;
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

class FilamentAccountingPlugin implements Plugin
{
    /** @var array<int, BankFeedDriver> */
    protected array $bankFeedDrivers = [];

    protected bool $hasDashboard = true;

    protected bool $hasCustomers = true;

    protected bool $hasSuppliers = true;

    protected bool $hasCatalog = true;

    protected bool $hasSalesInvoices = true;

    protected bool $hasPurchaseInvoices = true;

    protected bool $hasBankReconciliation = true;

    protected bool $hasJournal = true;

    protected bool $hasChart = true;

    protected bool $hasTaxAndRules = true;

    protected bool $hasReports = true;

    protected bool $hasSettings = true;

    protected bool $hasAudit = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        $plugin = filament(app(static::class)->getId());

        if (! $plugin instanceof static) {
            throw new \RuntimeException('The filament-accounting plugin is not registered on the current panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-accounting';
    }

    /**
     * @param  array<int, BankFeedDriver>  $drivers
     */
    public function bankFeeds(array $drivers): static
    {
        $this->bankFeedDrivers = $drivers;

        return $this;
    }

    public function customers(bool $condition = true): static
    {
        $this->hasCustomers = $condition;

        return $this;
    }

    public function suppliers(bool $condition = true): static
    {
        $this->hasSuppliers = $condition;

        return $this;
    }

    public function catalog(bool $condition = true): static
    {
        $this->hasCatalog = $condition;

        return $this;
    }

    public function salesInvoices(bool $condition = true): static
    {
        $this->hasSalesInvoices = $condition;

        return $this;
    }

    public function purchaseInvoices(bool $condition = true): static
    {
        $this->hasPurchaseInvoices = $condition;

        return $this;
    }

    public function bankReconciliation(bool $condition = true): static
    {
        $this->hasBankReconciliation = $condition;

        return $this;
    }

    public function journal(bool $condition = true): static
    {
        $this->hasJournal = $condition;

        return $this;
    }

    public function dashboard(bool $condition = true): static
    {
        $this->hasDashboard = $condition;

        return $this;
    }

    public function chart(bool $condition = true): static
    {
        $this->hasChart = $condition;

        return $this;
    }

    public function taxAndRules(bool $condition = true): static
    {
        $this->hasTaxAndRules = $condition;

        return $this;
    }

    public function reports(bool $condition = true): static
    {
        $this->hasReports = $condition;

        return $this;
    }

    public function settings(bool $condition = true): static
    {
        $this->hasSettings = $condition;

        return $this;
    }

    public function audit(bool $condition = true): static
    {
        $this->hasAudit = $condition;

        return $this;
    }

    public function hasCustomers(): bool
    {
        return $this->hasCustomers && $this->enabled('customers');
    }

    public function hasSuppliers(): bool
    {
        return $this->hasSuppliers && $this->enabled('suppliers');
    }

    public function hasJournal(): bool
    {
        return $this->hasJournal && $this->enabled('journal');
    }

    public function register(Panel $panel): void
    {
        $pages = [];
        $resources = [];

        if ($this->enabled('dashboard') && $this->hasDashboard) {
            $pages[] = AccountingOverview::class;
        }

        if ($this->enabled('customers') && $this->hasCustomers) {
            $resources[] = CustomerResource::class;
        }

        if ($this->enabled('suppliers') && $this->hasSuppliers) {
            $resources[] = SupplierResource::class;
        }

        if ($this->enabled('catalog') && $this->hasCatalog) {
            $resources[] = CatalogItemResource::class;
        }

        if ($this->enabled('sales_invoices') && $this->hasSalesInvoices) {
            $resources[] = SalesInvoiceResource::class;
        }

        if ($this->enabled('purchase_invoices') && $this->hasPurchaseInvoices) {
            $resources[] = PurchaseInvoiceResource::class;
        }

        if ($this->enabled('bank_reconciliation') && $this->hasBankReconciliation) {
            $resources[] = BankStatementLineResource::class;
            $pages[] = ReconciliationPage::class;
        }

        if ($this->enabled('journal') && $this->hasJournal) {
            $resources[] = JournalEntryResource::class;
        }

        if ($this->enabled('chart_of_accounts') && $this->hasChart) {
            $resources[] = LedgerAccountResource::class;
        }

        if ($this->enabled('tax_and_posting_rules') && $this->hasTaxAndRules) {
            $resources[] = PostingRuleResource::class;
        }

        if ($this->enabled('settings') && $this->hasSettings) {
            $resources[] = LegalEntityResource::class;
        }

        if ($this->enabled('audit') && $this->hasAudit) {
            $resources[] = AuditEventResource::class;
        }

        $panel
            ->colors([
                'accounting-negative' => Color::hex('#0072B2'),
                'accounting-positive' => Color::hex('#009E73'),
            ])
            ->navigationItems(AccountingNavigation::items())
            ->pages($pages)
            ->resources($resources);
    }

    public function boot(Panel $panel): void
    {
        $registry = app(BankFeedDriverRegistry::class);

        foreach ($this->bankFeedDrivers as $driver) {
            $registry->register($driver);
        }
    }

    protected function enabled(string $feature): bool
    {
        return (bool) config("filament-accounting.features.{$feature}", true);
    }
}
