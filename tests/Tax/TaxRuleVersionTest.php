<?php

namespace FilamentAccounting\Tests\Tax;

use FilamentAccounting\Exceptions\TaxRulePeriodOverlapException;
use FilamentAccounting\Exceptions\TaxRuleVersionImmutableException;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Models\TaxRuleVersion;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TaxRuleVersionTest extends TestCase
{
    #[Test]
    public function german_historical_rates_are_selected_on_every_period_boundary(): void
    {
        $entity = $this->makeEntity();

        $standard = TaxCode::query()->where('legal_entity_id', $entity->getKey())->where('code', 'DE-19')->firstOrFail();
        $reduced = TaxCode::query()->where('legal_entity_id', $entity->getKey())->where('code', 'DE-7')->firstOrFail();

        $this->assertSame(1900, $standard->versionOn('2020-06-30')?->rate_bp);
        $this->assertSame(1600, $standard->versionOn('2020-07-01')?->rate_bp);
        $this->assertSame(1600, $standard->versionOn('2020-12-31')?->rate_bp);
        $this->assertSame(1900, $standard->versionOn('2021-01-01')?->rate_bp);
        $this->assertSame(700, $reduced->versionOn('2020-06-30')?->rate_bp);
        $this->assertSame(500, $reduced->versionOn('2020-07-01')?->rate_bp);
        $this->assertSame(500, $reduced->versionOn('2020-12-31')?->rate_bp);
        $this->assertSame(700, $reduced->versionOn('2021-01-01')?->rate_bp);
    }

    #[Test]
    public function validity_periods_for_one_tax_code_cannot_overlap(): void
    {
        $entity = $this->makeEntity();
        $code = TaxCode::query()->where('legal_entity_id', $entity->getKey())->where('code', 'DE-19')->firstOrFail();

        $this->expectException(TaxRulePeriodOverlapException::class);

        TaxRuleVersion::query()->create([
            'tax_code_id' => $code->getKey(),
            'valid_from' => '2020-06-30',
            'valid_to' => '2020-07-02',
            'rate_bp' => 1700,
            'recoverable' => true,
            'category' => 'standard',
        ]);
    }

    #[Test]
    public function a_referenced_tax_rule_version_is_immutable(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $version = TaxRuleVersion::query()->findOrFail($invoice->lines->firstOrFail()->tax_rule_version_id);
        $version->rate_bp = 1800;

        $this->expectException(TaxRuleVersionImmutableException::class);
        $version->save();
    }
}
