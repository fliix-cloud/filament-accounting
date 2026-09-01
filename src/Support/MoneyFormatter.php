<?php

namespace FilamentAccounting\Support;

use Illuminate\Support\Number;

final class MoneyFormatter
{
    public static function format(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);

        return Number::currency(
            (float) ExactMoney::ofMinor($minor, $currency)->decimalString(),
            in: $currency,
            locale: app()->getLocale(),
        );
    }
}
