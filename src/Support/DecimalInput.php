<?php

namespace FilamentAccounting\Support;

use FilamentAccounting\Exceptions\InvalidMoneyException;

final class DecimalInput
{
    public const RULE = 'regex:/^[+-]?\d+(?:[.,]\d+)?$/D';

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^[+-]?\d+(?:[.,]\d+)?$/D', $value)) {
            throw new InvalidMoneyException(__('filament-accounting::errors.invalid_decimal'));
        }

        return str_replace(',', '.', $value);
    }
}
