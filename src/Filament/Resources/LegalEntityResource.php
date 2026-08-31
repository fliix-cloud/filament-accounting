<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\LegalEntityState;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\LegalEntityResource\Pages\CreateLegalEntity;
use FilamentAccounting\Filament\Resources\LegalEntityResource\Pages\EditLegalEntity;
use FilamentAccounting\Filament\Resources\LegalEntityResource\Pages\ListLegalEntities;
use FilamentAccounting\Models\LegalEntity;

class LegalEntityResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = LegalEntity::class;

    protected static ?string $slug = 'accounting/legal-entities';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::ADMINISTRATION;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.legal_entities');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.legal_entity.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.legal_entity.plural');
    }

    protected static function ability(): string
    {
        return 'manage_settings';
    }

    protected static function scopesToLegalEntity(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
            TextInput::make('trading_name')->label(__('filament-accounting::fields.trading_name')),
            TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2)->required(),
            TextInput::make('base_currency')->label(__('filament-accounting::fields.base_currency'))->maxLength(3)->required(),
            TextInput::make('locale')->label(__('filament-accounting::fields.locale')),
            TextInput::make('timezone')->label(__('filament-accounting::fields.timezone')),
            TextInput::make('fiscal_year_start_month')->numeric()->label(__('filament-accounting::fields.fiscal_year_start')),
            TextInput::make('compliance_profile_key')->label(__('filament-accounting::fields.compliance_profile')),
            Select::make('state')->label(__('filament-accounting::fields.state'))->options([
                LegalEntityState::Active->value => __('filament-accounting::statuses.entity.active'),
                LegalEntityState::Inactive->value => __('filament-accounting::statuses.entity.inactive'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->searchable(),
            TextColumn::make('country_code')->label(__('filament-accounting::fields.country')),
            TextColumn::make('base_currency')->label(__('filament-accounting::fields.currency')),
            TextColumn::make('state')->badge()->label(__('filament-accounting::fields.state')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalEntities::route('/'),
            'create' => CreateLegalEntity::route('/create'),
            'edit' => EditLegalEntity::route('/{record}/edit'),
        ];
    }
}
