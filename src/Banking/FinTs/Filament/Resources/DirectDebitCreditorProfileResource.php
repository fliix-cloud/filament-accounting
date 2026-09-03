<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages\CreateDirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages\EditDirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages\ListDirectDebitCreditorProfiles;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use Illuminate\Database\Eloquent\Builder;

class DirectDebitCreditorProfileResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = DirectDebitCreditorProfile::class;

    protected static ?string $slug = 'bank/direct-debit-creditors';

    protected static ?int $navigationSort = 42;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::banking/fints/navigation.direct_debit_creditors');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit_creditor.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit_creditor.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('filament-accounting::banking/fints/fields.creditor_name'))
                ->required()
                ->maxLength(255),
            TextInput::make('creditor_identifier')
                ->label(__('filament-accounting::banking/fints/fields.creditor_identifier'))
                ->helperText(__('filament-accounting::banking/fints/fields.creditor_identifier_help'))
                ->required()
                ->maxLength(35),
            Toggle::make('is_default')
                ->label(__('filament-accounting::banking/fints/fields.default')),
            TextInput::make('street')
                ->label(__('filament-accounting::banking/fints/fields.street'))
                ->maxLength(255),
            TextInput::make('building_number')
                ->label(__('filament-accounting::banking/fints/fields.building_number'))
                ->maxLength(32),
            TextInput::make('postal_code')
                ->label(__('filament-accounting::banking/fints/fields.postal_code'))
                ->maxLength(32),
            TextInput::make('city')
                ->label(__('filament-accounting::banking/fints/fields.city'))
                ->maxLength(255),
            TextInput::make('country')
                ->label(__('filament-accounting::banking/fints/fields.country'))
                ->default('DE')
                ->length(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('creditor_identifier')
                    ->label(__('filament-accounting::banking/fints/fields.creditor_identifier'))
                    ->searchable(),
                IconColumn::make('is_default')
                    ->boolean()
                    ->label(__('filament-accounting::banking/fints/fields.default')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectDebitCreditorProfiles::route('/'),
            'create' => CreateDirectDebitCreditorProfile::route('/create'),
            'edit' => EditDirectDebitCreditorProfile::route('/{record}/edit'),
        ];
    }
}
