<?php

namespace FilamentAccounting\Banking\FinTs\Support;

final class ProductRegistration
{
    public static function id(): string
    {
        return trim((string) config('filament-accounting.banking.fints.product.id', ''));
    }

    public static function isConfigured(): bool
    {
        return self::id() !== '';
    }
}
