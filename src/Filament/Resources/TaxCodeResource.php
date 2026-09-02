<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\TaxCodeResource\Pages\CreateTaxCode;
use FilamentAccounting\Filament\Resources\TaxCodeResource\Pages\EditTaxCode;
use FilamentAccounting\Filament\Resources\TaxCodeResource\Pages\ListTaxCodes;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class TaxCodeResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = TaxCode::class;

    protected static ?string $slug = 'accounting/tax-codes';

    protected static ?int $navigationSort = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::LEDGER;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.tax_codes');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.tax_code.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.tax_code.plural');
    }

    protected static function ability(): string
    {
        return 'manage_settings';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('filament-accounting::fields.code'))->required()->maxLength(32),
            TextInput::make('name')->label(__('filament-accounting::fields.name'))->required(),
            Select::make('direction')
                ->label(__('filament-accounting::fields.direction'))
                ->options([
                    'output' => __('filament-accounting::fields.output_tax'),
                    'input' => __('filament-accounting::fields.input_tax'),
                    'both' => __('filament-accounting::fields.both_directions'),
                ])
                ->required(),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
            Repeater::make('versions')
                ->relationship()
                ->label(__('filament-accounting::fields.tax_rule_versions'))
                ->schema([
                    DatePicker::make('valid_from')->label(__('filament-accounting::fields.valid_from'))->required(),
                    DatePicker::make('valid_to')->label(__('filament-accounting::fields.valid_to')),
                    TextInput::make('rate_bp')
                        ->label(__('filament-accounting::fields.tax_rate'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->required()
                        ->formatStateUsing(fn (mixed $state): string => number_format(((int) $state) / 100, 2, '.', ''))
                        ->dehydrateStateUsing(fn (mixed $state): int => (int) round(((float) $state) * 100)),
                    Select::make('category')
                        ->label(__('filament-accounting::fields.tax_treatment'))
                        ->options([
                            'standard' => __('filament-accounting::fields.tax_treatments.standard'),
                            'reduced' => __('filament-accounting::fields.tax_treatments.reduced'),
                            'zero' => __('filament-accounting::fields.tax_treatments.zero'),
                            'exempt' => __('filament-accounting::fields.tax_treatments.exempt'),
                            'non_taxable' => __('filament-accounting::fields.tax_treatments.non_taxable'),
                            'reverse_charge' => __('filament-accounting::fields.tax_treatments.reverse_charge'),
                            'intra_community_acquisition' => __('filament-accounting::fields.tax_treatments.intra_community_acquisition'),
                        ])
                        ->live()
                        ->required(),
                    Toggle::make('recoverable')->label(__('filament-accounting::fields.recoverable'))->default(true),
                    TextInput::make('reason')
                        ->label(__('filament-accounting::fields.exemption_reason'))
                        ->required(fn (Get $get): bool => in_array($get('category'), ['exempt', 'non_taxable'], true)),
                    KeyValue::make('export_mapping')->label(__('filament-accounting::fields.export_mapping')),
                ])
                ->defaultItems(1)
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('filament-accounting::fields.code'))->searchable(),
            TextColumn::make('name')->label(__('filament-accounting::fields.name'))->searchable(),
            TextColumn::make('direction')->label(__('filament-accounting::fields.direction')),
            TextColumn::make('versions_count')->counts('versions')->label(__('filament-accounting::fields.versions')),
            IconColumn::make('is_active')->boolean()->label(__('filament-accounting::fields.is_active')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
            'create' => CreateTaxCode::route('/create'),
            'edit' => EditTaxCode::route('/{record}/edit'),
        ];
    }
}
