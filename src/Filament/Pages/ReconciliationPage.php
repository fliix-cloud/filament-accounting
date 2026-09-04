<?php

namespace FilamentAccounting\Filament\Pages;

use Filament\Pages\Page;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class ReconciliationPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'accounting/reconcile';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament-accounting::pages.reconciliation';

    #[Url]
    public ?string $line = null;

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.reconciliation');
    }

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('banking');
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-accounting::fields.reconciliation_assistant');
    }

    public static function canAccess(): bool
    {
        return app(AccountingAuthorizer::class)->can('draft_reconciliation');
    }
}
