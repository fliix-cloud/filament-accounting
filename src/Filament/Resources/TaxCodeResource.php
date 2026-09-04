<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
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

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    public static function getNavigationGroup(): ?string
    {
        return AccountingNavigation::section('settings');
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
            TextInput::make('name')
                ->label(__('filament-accounting::fields.tax_treatment'))
                ->disabled()
                ->dehydrated(false),
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
                    TextInput::make('reason')
                        ->label(__('filament-accounting::fields.exemption_reason'))
                        ->required(fn (Get $get): bool => in_array($get('category'), ['exempt', 'non_taxable'], true)),
                ])
                ->itemLabel(function (array $state): string {
                    $from = filled($state['valid_from'] ?? null) ? (string) $state['valid_from'] : '…';
                    $to = filled($state['valid_to'] ?? null) ? (string) $state['valid_to'] : '∞';
                    $rate = (float) ($state['rate_bp'] ?? 0);
                    if ($rate > 100) {
                        $rate /= 100;
                    }
                    $category = (string) ($state['category'] ?? 'standard');

                    return $from.' – '.$to.' · '.number_format($rate, 2, ',', '.').' % · '.__('filament-accounting::fields.tax_treatments.'.$category);
                })
                ->addActionLabel(__('filament-accounting::actions.add_tax_rate_period'))
                ->collapsible()
                ->reorderable(false)
                ->deletable(false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('filament-accounting::fields.name'))->searchable(),
            TextColumn::make('current_rate')
                ->label(__('filament-accounting::fields.tax_rate'))
                ->state(function (TaxCode $record): string {
                    $version = $record->versionOn(now()->toDateString());

                    return $version === null
                        ? '—'
                        : number_format($version->rate_bp / 100, 2, ',', '.').' %';
                }),
            TextColumn::make('versions_count')->counts('versions')->label(__('filament-accounting::fields.tax_rate_periods')),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
            'edit' => EditTaxCode::route('/{record}/edit'),
        ];
    }
}
