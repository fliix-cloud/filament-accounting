<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\EditAccountingBankAccount;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\ListAccountingBankAccounts;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class AccountingBankAccountResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = AccountingBankAccount::class;

    protected static ?string $slug = 'accounting/bank-accounts';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANKING;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.bank_accounts');
    }

    protected static function ability(): string
    {
        return 'manage_bank_connections';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)
            ->constrain(parent::getEloquentQuery())
            ->orderByDesc('is_available')
            ->orderBy('display_name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name'))->required(),
            TextInput::make('iban')->label(__('filament-accounting::fields.iban'))->disabled(),
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->disabled(),
            Toggle::make('is_enabled')
                ->label(__('filament-accounting::fields.bank_account_enabled'))
                ->helperText(__('filament-accounting::fields.bank_account_enabled_help')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_name')->label(__('filament-accounting::fields.display_name'))->searchable(),
            TextColumn::make('iban')->label(__('filament-accounting::fields.iban')),
            TextColumn::make('booked_balance_minor')
                ->label(__('filament-accounting::fields.booked_balance'))
                ->formatStateUsing(fn (?int $state, AccountingBankAccount $record): ?string => $record->formattedBalance($state)),
            IconColumn::make('is_available')->boolean()->label(__('filament-accounting::fields.bank_account_available')),
            IconColumn::make('is_enabled')->boolean()->label(__('filament-accounting::fields.bank_account_enabled')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingBankAccounts::route('/'),
            'edit' => EditAccountingBankAccount::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
