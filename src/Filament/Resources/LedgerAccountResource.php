<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\NormalBalance;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\LedgerAccountResource\Pages\CreateLedgerAccount;
use FilamentAccounting\Filament\Resources\LedgerAccountResource\Pages\EditLedgerAccount;
use FilamentAccounting\Filament\Resources\LedgerAccountResource\Pages\ListLedgerAccounts;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;

class LedgerAccountResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = LedgerAccount::class;

    protected static ?string $slug = 'accounting/accounts';

    protected static ?int $navigationSort = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.accounts');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.ledger_account.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.ledger_account.plural');
    }

    protected static function ability(): string
    {
        return 'manage_chart';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('filament-accounting::fields.code'))->required(),
            TextInput::make('name')->label(__('filament-accounting::fields.name'))->required(),
            Select::make('type')->label(__('filament-accounting::fields.type'))->options(collect(AccountType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value]))->required(),
            Select::make('normal_balance')->label(__('filament-accounting::fields.normal_balance'))->options(collect(NormalBalance::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value]))->required(),
            Select::make('currency')->label(__('filament-accounting::fields.currency'))->options(ReferenceData::currencies())->searchable(),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('filament-accounting::fields.code'))->searchable(),
            TextColumn::make('name')->label(__('filament-accounting::fields.name'))->searchable(),
            TextColumn::make('type')->label(__('filament-accounting::fields.type')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerAccounts::route('/'),
            'create' => CreateLedgerAccount::route('/create'),
            'edit' => EditLedgerAccount::route('/{record}/edit'),
        ];
    }
}
