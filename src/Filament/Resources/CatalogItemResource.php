<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\CreateCatalogItem;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\EditCatalogItem;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\ListCatalogItems;
use FilamentAccounting\Models\CatalogItem;

class CatalogItemResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = CatalogItem::class;

    protected static ?string $slug = 'accounting/catalog';

    protected static ?int $navigationSort = 22;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.catalog_item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.catalog_item.plural');
    }

    protected static function ability(): string
    {
        return 'manage_catalog';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')->label(__('filament-accounting::fields.sku')),
            TextInput::make('name')->label(__('filament-accounting::fields.name'))->required(),
            Select::make('type')->label(__('filament-accounting::fields.type'))->options([
                CatalogItemType::Service->value => CatalogItemType::Service->value,
                CatalogItemType::Product->value => CatalogItemType::Product->value,
            ])->required(),
            TextInput::make('unit')->label(__('filament-accounting::fields.unit'))->default('unit'),
            TextInput::make('default_quantity')->label(__('filament-accounting::fields.quantity'))->default('1'),
            TextInput::make('default_unit_price_minor')->label(__('filament-accounting::fields.unit_price'))->numeric()->required(),
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->maxLength(3)->required(),
            TextInput::make('default_tax_code')->label(__('filament-accounting::fields.tax_code')),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku')->label(__('filament-accounting::fields.sku')),
            TextColumn::make('name')->label(__('filament-accounting::fields.name'))->searchable(),
            TextColumn::make('type')->label(__('filament-accounting::fields.type')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogItems::route('/'),
            'create' => CreateCatalogItem::route('/create'),
            'edit' => EditCatalogItem::route('/{record}/edit'),
        ];
    }
}
