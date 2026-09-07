<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LineMoneyCalculatorTest extends TestCase
{
    #[Test]
    public function percent_and_amount_discounts_reduce_net(): void
    {
        $this->assertSame(9000, LineMoneyCalculator::netAfterDiscount(10000, '10%', 'EUR'));
        $this->assertSame(8500, LineMoneyCalculator::netAfterDiscount(10000, '15.00', 'EUR'));
        $this->assertSame(10000, LineMoneyCalculator::netAfterDiscount(10000, null, 'EUR'));
        $this->assertSame(10000, LineMoneyCalculator::netAfterDiscount(10000, '', 'EUR'));
    }

    #[Test]
    public function invalid_discounts_are_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);
        LineMoneyCalculator::netAfterDiscount(10000, '110%', 'EUR');
    }
}
