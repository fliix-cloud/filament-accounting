<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Enums\CatalogUnit;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\CreateCatalogItem;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\EditCatalogItem;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\ListCatalogItems;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;

class CatalogItemResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = CatalogItem::class;

    protected static ?string $slug = 'accounting/catalog';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('master_data');
    }

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

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')->label(__('filament-accounting::fields.sku')),
            TextInput::make('name')->label(__('filament-accounting::fields.name'))->required(),
            Select::make('type')->label(__('filament-accounting::fields.type'))->options([
                CatalogItemType::Service->value => __('filament-accounting::fields.catalog_types.service'),
                CatalogItemType::Product->value => __('filament-accounting::fields.catalog_types.product'),
            ])->required(),
            RichEditor::make('description')
                ->label(__('filament-accounting::fields.description'))
                ->toolbarButtons([['bold', 'italic'], ['bulletList', 'orderedList']])
                ->columnSpanFull(),
            Select::make('unit')
                ->label(__('filament-accounting::fields.unit'))
                ->options(fn (Get $get): array => ReferenceData::catalogUnits($get('unit')))
                ->default(CatalogUnit::Piece->value)
                ->searchable()
                ->required(),
            TextInput::make('default_quantity')->label(__('filament-accounting::fields.quantity'))->default('1'),
            TextInput::make('default_unit_price')->label(__('filament-accounting::fields.unit_price'))->numeric()->step('0.01')->required(),
            Select::make('currency')->label(__('filament-accounting::fields.currency'))->options(ReferenceData::currencies())->searchable()->required(),
            Select::make('default_tax_code')
                ->label(__('filament-accounting::fields.tax_code'))
                ->options(fn (): array => TaxCode::query()
                    ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->pluck('name', 'code')
                    ->all())
                ->searchable(),
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
