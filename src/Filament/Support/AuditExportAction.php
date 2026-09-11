<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;

final class AuditExportAction
{
    public static function make(): Action
    {
        return Action::make('exportAudit')
            ->label(__('filament-accounting::actions.export_audit'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (LegalEntity $record): bool => (string) $record->getKey() === (string) app(LegalEntityScope::class)->current()?->getKey()
                && app(AccountingAuthorizer::class)->can('export_audit', $record))
            ->url(fn (LegalEntity $record): string => route('filament-accounting.audit-export', ['legalEntity' => $record->getRouteKey()]));
    }
}
