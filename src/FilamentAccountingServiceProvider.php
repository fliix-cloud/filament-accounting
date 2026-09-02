<?php

namespace FilamentAccounting;

use FilamentAccounting\Audit\FilesystemAuditAnchorStore;
use FilamentAccounting\Commands\CreateAuditAnchorCommand;
use FilamentAccounting\Commands\ExportAuditEvidenceCommand;
use FilamentAccounting\Commands\InstallCommand;
use FilamentAccounting\Commands\SeedProfileCommand;
use FilamentAccounting\Commands\VerifyAuditEvidenceCommand;
use FilamentAccounting\Commands\VerifyCommand;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Contracts\AccountingExporter;
use FilamentAccounting\Contracts\AccountingTenancyContextActivator;
use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Contracts\BankFeedDriverRegistry;
use FilamentAccounting\Contracts\EInvoiceAdapter;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Contracts\ReconciliationMatcher;
use FilamentAccounting\Documents\FpdfInvoiceRenderer;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Export\GenericJournalCsvExporter;
use FilamentAccounting\Ledger\FirstPartyLedgerEngine;
use FilamentAccounting\Livewire\ReconciliationAssistant;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Reconciliation\DeterministicReconciliationMatcher;
use FilamentAccounting\Support\BankFeedRegistry;
use FilamentAccounting\Support\BankSourceLinkRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAccountingServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-accounting';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-accounting')
            ->hasCommands([
                CreateAuditAnchorCommand::class,
                ExportAuditEvidenceCommand::class,
                InstallCommand::class,
                SeedProfileCommand::class,
                VerifyAuditEvidenceCommand::class,
                VerifyCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-accounting.php', 'filament-accounting');
        $this->publishes([
            __DIR__.'/../config/filament-accounting.php' => config_path('filament-accounting.php'),
        ], 'filament-accounting-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-accounting');

        $this->app->singleton(config('filament-accounting.ownership.entity_resolver'));
        $this->app->singleton(AccountingEntityResolver::class, function ($app) {
            return $app->make(config('filament-accounting.ownership.entity_resolver'));
        });
        $this->app->singleton(AccountingActorResolver::class, function ($app) {
            return $app->make(config('filament-accounting.actor.resolver'));
        });
        $this->app->singleton(AccountingTenancyContextActivator::class, function ($app) {
            return $app->make(config('filament-accounting.tenancy.context_activator'));
        });
        $this->app->singleton(AccountingAuthorizer::class, function ($app) {
            return $app->make(config('filament-accounting.authorization.authorizer'));
        });
        $this->app->singleton(LegalEntityScope::class);
        $this->app->singleton(LedgerEngine::class, FirstPartyLedgerEngine::class);
        $this->app->singleton(BankFeedDriverRegistry::class, BankFeedRegistry::class);
        $this->app->singleton(BankSourceLinkRegistry::class);
        $this->app->singleton(ReconciliationMatcher::class, DeterministicReconciliationMatcher::class);
        $this->app->singleton(EInvoiceAdapter::class, ZugferdEInvoiceAdapter::class);
        $this->app->singleton(InvoiceRenderer::class, FpdfInvoiceRenderer::class);
        $this->app->singleton(AccountingExporter::class, GenericJournalCsvExporter::class);
        $this->app->singleton(AuditAnchorStore::class, function ($app) {
            return $app->make(config('filament-accounting.audit.anchor.store', FilesystemAuditAnchorStore::class));
        });
    }

    protected function registerPackageTranslations(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'lang';

        $this->loadTranslationsFrom($path, 'filament-accounting');
        $this->loadJsonTranslationsFrom($path);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $path => function_exists('lang_path')
                    ? lang_path('vendor/filament-accounting')
                    : resource_path('lang/vendor/filament-accounting'),
            ], 'filament-accounting-translations');
        }
    }

    protected function getPackageBaseDir(): string
    {
        return dirname(__DIR__).'/src';
    }

    public function packageBooted(): void
    {
        $this->registerPackageTranslations();

        if ($this->app->bound('livewire.finder')) {
            Livewire::component('filament-accounting.reconciliation-assistant', ReconciliationAssistant::class);
        }

        foreach (config('filament-accounting.authorization.abilities', []) as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user = null) => $user !== null);
            }
        }
    }
}
