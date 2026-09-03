<?php

namespace FilamentAccounting\Banking\FinTs\Support;

final class Money
{
    public static function fromFloat(float $amount, int $scale = 2): string
    {
        return number_format($amount, $scale, '.', '');
    }

    public static function normalize(string|int|float $amount, int $scale = 2): string
    {
        if (is_float($amount)) {
            return self::fromFloat($amount, $scale);
        }

        $amount = trim((string) $amount);
        if (! is_numeric($amount)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }

        return number_format((float) $amount, $scale, '.', '');
    }

    public static function isPositive(string $amount): bool
    {
        return (float) $amount > 0;
    }
}
