<?php

namespace FilamentAccounting\Filament\Concerns;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

trait HasAccountingNavigation
{
    public static function getNavigationGroup(): ?string
    {
        return __('filament-accounting::navigation.group');
    }

    public static function canViewAny(): bool
    {
        return app(AccountingAuthorizer::class)->can(static::ability());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    protected static function ability(): string
    {
        return 'view';
    }

    protected static function scopesToLegalEntity(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::scopesToLegalEntity()) {
            return $query;
        }

        try {
            return app(LegalEntityScope::class)->constrain($query);
        } catch (\Throwable) {
            return $query->whereRaw('1 = 0');
        }
    }
}
