<?php

namespace FilamentAccounting\Tests\Ownership;

use FilamentAccounting\Models\Document;
use FilamentAccounting\Ownership\ConfiguredLegalEntityResolver;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EntityIsolationTest extends TestCase
{
    #[Test]
    public function two_entities_do_not_share_documents_even_with_the_same_number_shape(): void
    {
        $this->actingAs($this->makeUser());
        $alpha = $this->makeEntity(['legal_name' => 'Alpha GmbH']);
        $customerA = $this->makeParty($alpha, ['legal_name' => 'Customer A']);
        $invoiceA = app(IssueSalesInvoice::class)->handle($alpha, [
            'party_id' => $customerA->getKey(),
            'issue_date' => '2026-01-15',
            'currency' => 'EUR',
            'lines' => [['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);

        $beta = $this->makeEntity(['legal_name' => 'Beta GmbH']);
        $customerB = $this->makeParty($beta, ['legal_name' => 'Customer B']);
        $invoiceB = app(IssueSalesInvoice::class)->handle($beta, [
            'party_id' => $customerB->getKey(),
            'issue_date' => '2026-01-15',
            'currency' => 'EUR',
            'lines' => [['description' => 'B', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);

        $this->assertSame($invoiceA->number, $invoiceB->number);

        app(ConfiguredLegalEntityResolver::class)->bind($alpha);
        $scoped = app(LegalEntityScope::class)->constrain(Document::query())->pluck('id')->all();
        $this->assertContains($invoiceA->getKey(), $scoped);
        $this->assertNotContains($invoiceB->getKey(), $scoped);
    }
}
