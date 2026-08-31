<?php

namespace FilamentAccounting\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class LineMoneyCalculator
{
    public static function roundingMode(): RoundingMode
    {
        return match (config('filament-accounting.money.rounding_mode', 'half_up')) {
            'half_even' => RoundingMode::HALF_EVEN,
            'half_down' => RoundingMode::HALF_DOWN,
            default => RoundingMode::HALF_UP,
        };
    }

    public static function netMinor(string $quantity, int $unitPriceMinor): int
    {
        return BigDecimal::of($quantity)
            ->multipliedBy($unitPriceMinor)
            ->toScale(0, self::roundingMode())
            ->toInt();
    }

    public static function taxMinor(int $netMinor, int $rateBp): int
    {
        if ($rateBp === 0 || $netMinor === 0) {
            return 0;
        }

        return BigDecimal::of($netMinor)
            ->multipliedBy($rateBp)
            ->dividedBy(10000, 0, self::roundingMode())
            ->toInt();
    }

    public static function netMinorFromGross(int $grossMinor, int $rateBp): int
    {
        if ($rateBp === 0 || $grossMinor === 0) {
            return $grossMinor;
        }

        return BigDecimal::of($grossMinor)
            ->multipliedBy(10000)
            ->dividedBy(10000 + $rateBp, 0, self::roundingMode())
            ->toInt();
    }
}
