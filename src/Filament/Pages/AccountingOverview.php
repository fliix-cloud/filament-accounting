<?php

namespace FilamentAccounting\Filament\Pages;

use Filament\Pages\Page;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Filament\Widgets\AccountingOverviewStats;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Contracts\Support\Htmlable;

class AccountingOverview extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $slug = 'accounting';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament-accounting::pages.overview';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.overview');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament-accounting::navigation.group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-accounting::navigation.overview');
    }

    public static function canAccess(): bool
    {
        return app(AccountingAuthorizer::class)->can('view');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountingOverviewStats::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        try {
            $entity = app(LegalEntityScope::class)->require();
        } catch (\Throwable) {
            $entity = null;
        }

        return [
            'entity' => $entity,
        ];
    }
}
