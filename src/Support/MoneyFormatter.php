<?php

namespace FilamentAccounting\Support;

final class MoneyFormatter
{
    public static function format(int $minor, string $currency): string
    {
        return ExactMoney::ofMinor($minor, $currency)->decimalString().' '.strtoupper($currency);
    }
}
