<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Enums\LegalEntityState;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\TaxRuleVersion;
use FilamentAccounting\Services\SeedGermanProfile;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

class HasUuidTest extends TestCase
{
    #[Test]
    public function german_profile_can_be_seeded_when_the_host_disables_model_events(): void
    {
        $entity = LegalEntity::query()->create([
            'legal_name' => 'Eventless Seeder GmbH',
            'country_code' => 'DE',
            'base_currency' => 'EUR',
            'locale' => 'de_DE',
            'timezone' => 'Europe/Berlin',
            'fiscal_year_start_month' => 1,
            'compliance_profile_key' => 'DE',
            'state' => LegalEntityState::Active,
        ]);

        Model::withoutEvents(fn () => app(SeedGermanProfile::class)->handle($entity));

        $versions = TaxRuleVersion::query()
            ->whereHas('taxCode', fn ($query) => $query->where('legal_entity_id', $entity->getKey()))
            ->get();

        $this->assertNotEmpty($versions);
        $this->assertTrue($versions->every(fn (TaxRuleVersion $version): bool => filled($version->uuid)));
    }
}
