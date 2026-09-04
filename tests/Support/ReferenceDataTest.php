<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Support\ReferenceData;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ReferenceDataTest extends TestCase
{
    #[Test]
    public function system_reference_values_are_bounded_dropdown_options(): void
    {
        app()->setLocale('de');

        $this->assertArrayHasKey('DE', ReferenceData::countries());
        $this->assertArrayHasKey('EUR', ReferenceData::currencies());
        $this->assertArrayHasKey('de', ReferenceData::locales());
        $this->assertArrayHasKey('Europe/Berlin', ReferenceData::timezones());
        $this->assertArrayHasKey('C62', ReferenceData::catalogUnits());
    }
}
