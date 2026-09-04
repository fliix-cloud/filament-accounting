<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\AuditEventResource\Pages\ListAuditEvents;
use FilamentAccounting\Filament\Resources\AuditEventResource\Pages\ViewAuditEvent;
use FilamentAccounting\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;

class AuditEventResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = AuditEvent::class;

    protected static ?string $slug = 'accounting/audit';

    protected static ?int $navigationSort = 99;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.audit');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.audit_event.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.audit_event.plural');
    }

    protected static function ability(): string
    {
        return 'view_audit';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderByDesc('occurred_at');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->label(__('filament-accounting::fields.occurred_at')),
            TextColumn::make('operation')->label(__('filament-accounting::fields.operation'))->searchable(),
            TextColumn::make('target_type')->label(__('filament-accounting::fields.target')),
            TextColumn::make('actor_id')->label(__('filament-accounting::fields.actor')),
            TextColumn::make('reason')->label(__('filament-accounting::fields.reason'))->limit(40),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
