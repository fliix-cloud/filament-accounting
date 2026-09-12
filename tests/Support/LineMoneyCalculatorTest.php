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

    #[Test]
    public function quantity_times_price_rounds_half_up_at_the_cent_boundary(): void
    {
        // 0.5 × 3 = 1.5 → half-up rounds to 2.
        $this->assertSame(2, LineMoneyCalculator::netMinor('0.5', 3));
        // 0.5 × 1 = 0.5 → half-up rounds to 1.
        $this->assertSame(1, LineMoneyCalculator::netMinor('0.5', 1));
        // Exact values are preserved without rounding drift.
        $this->assertSame(10007, LineMoneyCalculator::netMinor('1.0007', 10000));
    }

    #[Test]
    public function tax_rounds_half_up_to_the_nearest_cent(): void
    {
        // 1 × 5000 bp (50%) = 0.5 → half-up rounds to 1.
        $this->assertSame(1, LineMoneyCalculator::taxMinor(1, 5000));
        // 1 × 4500 bp (45%) = 0.45 → rounds down to 0.
        $this->assertSame(0, LineMoneyCalculator::taxMinor(1, 4500));
        // 19% on 10000 = 1900 exactly.
        $this->assertSame(1900, LineMoneyCalculator::taxMinor(10000, 1900));
        // Zero-rate and zero-net short-circuit the division.
        $this->assertSame(0, LineMoneyCalculator::taxMinor(1, 0));
        $this->assertSame(0, LineMoneyCalculator::taxMinor(0, 1900));
    }

    #[Test]
    public function discount_rounding_half_up_at_the_boundary(): void
    {
        // 1 × 50% = 0.5 → half-up rounds net to 1 (from a 1-cent line).
        $this->assertSame(1, LineMoneyCalculator::netAfterDiscount(1, '50%', 'EUR'));
        // 3 × 50% = 1.5 → half-up rounds to 2.
        $this->assertSame(2, LineMoneyCalculator::netAfterDiscount(3, '50%', 'EUR'));
    }
}
