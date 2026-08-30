<?php

namespace FilamentAccounting\Tests\Support;

use FilamentAccounting\Exceptions\CurrencyMismatchException;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ExactMoneyTest extends TestCase
{
    #[Test]
    public function it_handles_zero_two_and_three_decimal_currencies(): void
    {
        $jpy = ExactMoney::ofMinor(150, 'JPY');
        $eur = ExactMoney::ofString('12.34', 'EUR');
        $bhd = ExactMoney::ofMinor(1234, 'BHD');

        $this->assertSame(0, $jpy->scale);
        $this->assertSame(2, $eur->scale);
        $this->assertSame(3, $bhd->scale);
        $this->assertSame('12.34', $eur->decimalString());
        $this->assertSame('1.234', $bhd->decimalString());
        $this->assertSame(150, $jpy->plus(ExactMoney::zero('JPY'))->minorAmount);
    }

    #[Test]
    public function it_rejects_floats_and_invalid_strings(): void
    {
        $this->expectException(InvalidMoneyException::class);
        ExactMoney::ofString('12.345', 'EUR');
    }

    #[Test]
    public function it_rejects_currency_mismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        ExactMoney::ofMinor(100, 'EUR')->plus(ExactMoney::ofMinor(100, 'USD'));
    }

    #[Test]
    public function it_does_not_use_float_arithmetic(): void
    {
        $sum = ExactMoney::ofString('0.10', 'EUR')
            ->plus(ExactMoney::ofString('0.20', 'EUR'));

        $this->assertSame(30, $sum->minorAmount);
        $this->assertTrue($sum->equals(ExactMoney::ofString('0.30', 'EUR')));
    }
}
