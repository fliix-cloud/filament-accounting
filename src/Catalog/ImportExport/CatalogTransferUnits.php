<?php

namespace FilamentAccounting\Catalog\ImportExport;

use FilamentAccounting\Enums\CatalogUnit;

final class CatalogTransferUnits
{
    /** @return array<string, string> Internal code => readable transfer value. */
    public static function labels(?string $locale = null): array
    {
        $language = strtolower(substr($locale ?? app()->getLocale(), 0, 2)) === 'de' ? 'de' : 'en';
        $labels = [];
        foreach (CatalogUnit::cases() as $unit) {
            $labels[$unit->value] = __('filament-accounting::fields.catalog_units.'.$unit->value, [], $language);
        }

        return $labels;
    }

    public static function code(string $value): ?string
    {
        if (CatalogUnit::tryFrom($value) !== null) {
            return $value;
        }
        foreach (['de', 'en'] as $language) {
            $code = array_search($value, self::labels($language), true);
            if ($code !== false) {
                return $code;
            }
        }

        return null;
    }

    public static function label(string $code): string
    {
        return self::labels()[$code] ?? $code;
    }
}
