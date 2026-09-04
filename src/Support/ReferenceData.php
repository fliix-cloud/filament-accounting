<?php

namespace FilamentAccounting\Support;

use DateTimeZone;
use FilamentAccounting\Enums\CatalogUnit;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Locales;

final class ReferenceData
{
    /** @return array<string, string> */
    public static function countries(): array
    {
        return Countries::getNames(self::displayLocale());
    }

    /** @return array<string, string> */
    public static function currencies(): array
    {
        return Currencies::getNames(self::displayLocale());
    }

    /** @return array<string, string> */
    public static function locales(): array
    {
        return Locales::getNames(self::displayLocale());
    }

    /** @return array<string, string> */
    public static function timezones(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /** @return array<string, string> */
    public static function complianceProfiles(): array
    {
        return collect((array) config('filament-accounting.compliance.profiles', []))
            ->keys()
            ->mapWithKeys(function (mixed $key): array {
                $key = (string) $key;
                $translation = 'filament-accounting::fields.compliance_profiles.'.$key;

                return [$key => Lang::has($translation) ? __($translation) : $key];
            })
            ->all();
    }

    /** @return array<string, string> */
    public static function catalogUnits(?string $legacy = null): array
    {
        $options = collect(CatalogUnit::cases())
            ->mapWithKeys(fn (CatalogUnit $unit): array => [$unit->value => $unit->getLabel()])
            ->all();

        if (filled($legacy) && ! array_key_exists($legacy, $options)) {
            $options[$legacy] = __('filament-accounting::fields.legacy_value', ['value' => $legacy]);
        }

        return $options;
    }

    private static function displayLocale(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }
}
