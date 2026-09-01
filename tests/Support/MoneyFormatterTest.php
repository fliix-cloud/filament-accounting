<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Support\MoneyFormatter;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MoneyFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_currency_using_the_application_locale(): void
    {
        app()->setLocale('de');
        $this->assertSame('1.239,43 €', MoneyFormatter::format(123943, 'EUR'));

        app()->setLocale('en');
        $this->assertSame('€1,239.43', MoneyFormatter::format(123943, 'EUR'));
    }
}
