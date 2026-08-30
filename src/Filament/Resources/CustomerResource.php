<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\EditCustomer;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\ListCustomers;
use FilamentAccounting\Models\Party;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Party::class;

    protected static ?string $slug = 'accounting/customers';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.customers');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.customer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.customer.plural');
    }

    protected static function ability(): string
    {
        return 'manage_parties';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_customer', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name')),
            TextInput::make('email')->label(__('filament-accounting::fields.email'))->email(),
            TextInput::make('phone')->label(__('filament-accounting::fields.phone')),
            TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2),
            Toggle::make('is_customer')->label(__('filament-accounting::fields.is_customer'))->default(true),
            Toggle::make('is_supplier')->label(__('filament-accounting::fields.is_supplier')),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->searchable(),
                TextColumn::make('email')->label(__('filament-accounting::fields.email')),
                IconColumn::make('is_active')->boolean()->label(__('filament-accounting::fields.is_active')),
            ])
            ->defaultSort('legal_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return app(AccountingAuthorizer::class)->can('manage_parties');
    }
}
