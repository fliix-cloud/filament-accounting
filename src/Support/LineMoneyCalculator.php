<?php

namespace FilamentAccounting\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use FilamentAccounting\Exceptions\InvalidMoneyException;

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

    public static function netAfterDiscount(int $netMinor, ?string $discount, string $currency): int
    {
        if ($discount === null) {
            return $netMinor;
        }

        $discount = trim($discount);
        if ($discount === '') {
            return $netMinor;
        }

        if ($netMinor < 0) {
            throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'));
        }

        if (str_ends_with($discount, '%')) {
            $percent = trim(substr($discount, 0, -1));
            if ($percent === '' || ! is_numeric($percent)) {
                throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'));
            }

            try {
                $percentValue = BigDecimal::of($percent);
            } catch (\Throwable $e) {
                throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'), 0, $e);
            }

            if ($percentValue->isNegative() || $percentValue->isGreaterThan(100)) {
                throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'));
            }

            return BigDecimal::of($netMinor)
                ->multipliedBy(BigDecimal::of(100)->minus($percentValue))
                ->dividedBy(100, 0, self::roundingMode())
                ->toInt();
        }

        try {
            $amount = ExactMoney::ofString($discount, $currency)->minorAmount;
        } catch (InvalidMoneyException $e) {
            throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'), 0, $e);
        }

        if ($amount < 0 || $amount > $netMinor) {
            throw new InvalidMoneyException(__('filament-accounting::errors.invalid_line_discount'));
        }

        return $netMinor - $amount;
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
