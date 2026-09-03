<?php

namespace FilamentAccounting\Filament\Navigation;

use Filament\Navigation\NavigationItem;

final class AccountingNavigation
{
    public const SALES = 'filament-accounting.sales';

    public const PURCHASES = 'filament-accounting.purchases';

    public const BANKING = 'filament-accounting.banking';

    public const REPORTS = 'filament-accounting.reports';

    public const SETTINGS = 'filament-accounting.settings';

    /** @deprecated Use REPORTS. */
    public const LEDGER = self::REPORTS;

    /** @deprecated Use SETTINGS. */
    public const ADMINISTRATION = self::SETTINGS;

    /**
     * @return list<NavigationItem>
     */
    public static function items(): array
    {
        return [
            self::item(self::SALES, 'sales', 'heroicon-o-arrow-trending-up', 10),
            self::item(self::PURCHASES, 'purchases', 'heroicon-o-arrow-trending-down', 20),
            self::item(self::BANKING, 'banking', 'heroicon-o-banknotes', 30),
            self::item(self::REPORTS, 'reports', 'heroicon-o-chart-bar', 40),
            self::item(self::SETTINGS, 'settings', 'heroicon-o-cog-6-tooth', 90),
        ];
    }

    private static function item(string $key, string $translationKey, string $icon, int $sort): NavigationItem
    {
        return NavigationItem::make(
            fn (): string => __("filament-accounting::navigation.sections.{$translationKey}"),
        )
            ->key($key)
            ->group(fn (): string => __('filament-accounting::navigation.group'))
            ->icon($icon)
            ->sort($sort);
    }
}
