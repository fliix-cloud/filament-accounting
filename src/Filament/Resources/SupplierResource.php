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
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\EditSupplier;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use FilamentAccounting\Models\Party;
use Illuminate\Database\Eloquent\Builder;

class SupplierResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Party::class;

    protected static ?string $slug = 'accounting/suppliers';

    protected static ?int $navigationSort = 21;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

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
        return parent::getEloquentQuery()->where('is_supplier', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name')),
            TextInput::make('email')->label(__('filament-accounting::fields.email'))->email(),
            TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
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
