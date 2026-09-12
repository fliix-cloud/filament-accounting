<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\AuditEventResource;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

class AccountingResourceRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-accounting.features.chart_of_accounts', true);
        $app['config']->set('filament-accounting.features.tax_and_posting_rules', true);
        $app['config']->set('filament-accounting.features.audit', true);
    }

    #[Test]
    public function enabled_accounting_resources_have_working_authorized_index_routes(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeEntity();

        foreach ([LedgerAccountResource::class, PostingRuleResource::class, AuditEventResource::class] as $resource) {
            $this->get($resource::getUrl('index', panel: 'admin'))->assertOk();
        }
    }

    #[Test]
    public function registered_accounting_resources_still_require_their_abilities(): void
    {
        $this->actingAs($this->makeUser());
        $this->makeEntity();
        Gate::define('accounting.chart.manage', fn (): bool => false);
        Gate::define('accounting.audit.view', fn (): bool => false);

        foreach ([LedgerAccountResource::class, PostingRuleResource::class, AuditEventResource::class] as $resource) {
            $this->get($resource::getUrl('index', panel: 'admin'))->assertForbidden();
        }
    }
}
