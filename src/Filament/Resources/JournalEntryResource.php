<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\JournalEntryResource\Pages\ListJournalEntries;
use FilamentAccounting\Filament\Resources\JournalEntryResource\Pages\ViewJournalEntry;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = JournalEntry::class;

    protected static ?string $slug = 'accounting/journal';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.journal');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.journal_entry.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.journal_entry.plural');
    }

    protected static function ability(): string
    {
        return 'view_journal';
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
        return parent::getEloquentQuery()->with(['lines.ledgerAccount', 'period']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('sequence')->label(__('filament-accounting::fields.sequence')),
            TextEntry::make('posted_on')->date()->label(__('filament-accounting::fields.posted_on')),
            TextEntry::make('status')->badge()->label(__('filament-accounting::fields.state')),
            TextEntry::make('description')->label(__('filament-accounting::fields.description')),
            RepeatableEntry::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    TextEntry::make('ledgerAccount.code')->label(__('filament-accounting::fields.code')),
                    TextEntry::make('debit_minor')
                        ->label(__('filament-accounting::fields.debit'))
                        ->formatStateUsing(fn ($state, $record): string => MoneyFormatter::format((int) $state, $record->currency)),
                    TextEntry::make('credit_minor')
                        ->label(__('filament-accounting::fields.credit'))
                        ->formatStateUsing(fn ($state, $record): string => MoneyFormatter::format((int) $state, $record->currency)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sequence')->label(__('filament-accounting::fields.sequence'))->searchable(),
            TextColumn::make('posted_on')->date()->label(__('filament-accounting::fields.posted_on')),
            TextColumn::make('description')->label(__('filament-accounting::fields.description')),
            TextColumn::make('status')
                ->badge()
                ->label(__('filament-accounting::fields.state'))
                ->formatStateUsing(fn (JournalStatus $state): string => __('filament-accounting::statuses.journal.'.$state->value)),
            TextColumn::make('source_type')->label(__('filament-accounting::fields.source')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'view' => ViewJournalEntry::route('/{record}'),
        ];
    }
}
