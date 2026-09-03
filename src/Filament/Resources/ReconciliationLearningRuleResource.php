<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource\Pages\EditReconciliationLearningRule;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource\Pages\ListReconciliationLearningRules;
use FilamentAccounting\Models\ReconciliationLearningRule;

class ReconciliationLearningRuleResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = ReconciliationLearningRule::class;

    protected static ?string $slug = 'accounting/reconciliation-learning-rules';

    protected static ?int $navigationSort = 39;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANKING;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.learning_rules');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.reconciliation_learning_rule.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.reconciliation_learning_rule.plural');
    }

    protected static function ability(): string
    {
        return 'finalize_reconciliation';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('direction')
                ->label(__('filament-accounting::fields.direction'))
                ->options([
                    'incoming' => __('filament-accounting::fields.incoming'),
                    'outgoing' => __('filament-accounting::fields.outgoing'),
                ])
                ->disabled()
                ->dehydrated(false),
            Select::make('match_type')
                ->label(__('filament-accounting::fields.learning_match_type'))
                ->options([
                    'iban' => 'IBAN',
                    'counterparty_name' => __('filament-accounting::fields.counterparty'),
                    'purpose_pattern' => __('filament-accounting::fields.purpose'),
                ])
                ->disabled()
                ->dehydrated(false),
            TextInput::make('match_value')
                ->label(__('filament-accounting::fields.learning_match_value'))
                ->required()
                ->maxLength(255),
            TextInput::make('target_label')
                ->label(__('filament-accounting::fields.learning_target'))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('confirmed_count')
                ->label(__('filament-accounting::fields.confirmed_count'))
                ->disabled()
                ->dehydrated(false),
            Toggle::make('is_active')
                ->label(__('filament-accounting::fields.is_active')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('match_type')->label(__('filament-accounting::fields.learning_match_type')),
            TextColumn::make('match_value')->label(__('filament-accounting::fields.learning_match_value'))->searchable(),
            TextColumn::make('target_label')->label(__('filament-accounting::fields.learning_target'))->searchable(),
            TextColumn::make('confirmed_count')->label(__('filament-accounting::fields.confirmed_count')),
            IconColumn::make('is_active')->label(__('filament-accounting::fields.is_active'))->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReconciliationLearningRules::route('/'),
            'edit' => EditReconciliationLearningRule::route('/{record}/edit'),
        ];
    }
}
