<?php

namespace FilamentAccounting\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

final class AccountingNavigation
{
    public const BANK_SETTINGS = 'filament-accounting.bank-settings';

    /** @return list<NavigationGroup> */
    public static function groups(): array
    {
        return [
            NavigationGroup::make(fn (): string => self::section('banking')),
            NavigationGroup::make(fn (): string => self::section('reports')),
            NavigationGroup::make(fn (): string => self::section('master_data')),
            NavigationGroup::make(fn (): string => self::section('settings')),
        ];
    }

    /**
     * @return list<NavigationItem>
     */
    public static function items(): array
    {
        return [
            NavigationItem::make(fn (): string => __('filament-accounting::navigation.bank_settings'))
                ->key(self::BANK_SETTINGS)
                ->group(fn (): string => self::section('settings'))
                ->icon('heroicon-o-building-library')
                ->sort(10),
        ];
    }

    public static function section(string $key): string
    {
        return __("filament-accounting::navigation.sections.{$key}");
    }
}
