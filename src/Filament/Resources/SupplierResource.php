<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\EditSupplier;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class SupplierResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Party::class;

    protected static ?string $slug = 'accounting/suppliers';

    protected static ?int $navigationSort = 21;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::PURCHASES;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.suppliers');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.supplier.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.supplier.plural');
    }

    protected static function ability(): string
    {
        return 'manage_parties';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery())
            ->where('is_supplier', true)
            ->with(['addresses', 'taxIds', 'bankAccounts']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name')),
            TextInput::make('email')->label(__('filament-accounting::fields.email'))->email(),
            TextInput::make('phone')->label(__('filament-accounting::fields.phone')),
            TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2),
            TextInput::make('payment_terms_days')->label(__('filament-accounting::fields.payment_terms_days'))->numeric()->minValue(0)->default(14),
            TextInput::make('default_currency')->label(__('filament-accounting::fields.default_currency'))->maxLength(3)->default('EUR'),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
            Section::make(__('filament-accounting::fields.addresses'))
                ->schema([
                    Repeater::make('addresses')
                        ->label(__('filament-accounting::fields.addresses'))
                        ->relationship()
                        ->schema(CustomerResource::addressSchema())
                        ->collapsible()
                        ->defaultItems(0),
                ])
                ->columnSpanFull(),
            Section::make(__('filament-accounting::fields.tax_ids'))
                ->schema([
                    Repeater::make('taxIds')
                        ->label(__('filament-accounting::fields.tax_ids'))
                        ->relationship()
                        ->schema(CustomerResource::taxIdSchema())
                        ->collapsible()
                        ->defaultItems(0),
                ])
                ->columnSpanFull(),
            Section::make(__('filament-accounting::fields.bank_accounts'))
                ->schema([
                    Repeater::make('bankAccounts')
                        ->label(__('filament-accounting::fields.bank_accounts'))
                        ->relationship()
                        ->schema(CustomerResource::bankAccountSchema())
                        ->collapsible()
                        ->defaultItems(0),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->searchable(),
            TextColumn::make('email')->label(__('filament-accounting::fields.email')),
            IconColumn::make('is_active')->boolean()->label(__('filament-accounting::fields.is_active')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
