<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
use FilamentAccounting\Support\Sepa;

class LegalEntityResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = LegalEntity::class;

    protected static ?string $slug = 'accounting/legal-entities';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::SETTINGS;
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
            Section::make(__('filament-accounting::fields.company_details'))->schema([
                TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
                TextInput::make('trading_name')->label(__('filament-accounting::fields.trading_name')),
                TextInput::make('address_line1')->label(__('filament-accounting::fields.address_line1'))->required(),
                TextInput::make('address_line2')->label(__('filament-accounting::fields.address_line2')),
                TextInput::make('postal_code')->label(__('filament-accounting::fields.postal_code'))->required(),
                TextInput::make('city')->label(__('filament-accounting::fields.city'))->required(),
                TextInput::make('region')->label(__('filament-accounting::fields.region')),
                TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2)->required(),
                TextInput::make('tax_number')->label(__('filament-accounting::fields.tax_number')),
                TextInput::make('vat_id')->label(__('filament-accounting::fields.vat_id')),
                TextInput::make('email')->label(__('filament-accounting::fields.email'))->email(),
                TextInput::make('phone')->label(__('filament-accounting::fields.phone')),
                TextInput::make('website')->label(__('filament-accounting::fields.website'))->url(),
            ])->columns(2),
            Section::make(__('filament-accounting::fields.payment_details'))->schema([
                TextInput::make('invoice_bank_name')->label(__('filament-accounting::fields.bank_name')),
                TextInput::make('invoice_iban')
                    ->label(__('filament-accounting::fields.iban'))
                    ->maxLength(34)
                    ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (filled($value) && ! Sepa::isValidIban((string) $value)) {
                            $fail(__('filament-accounting::validation.iban'));
                        }
                    }),
                TextInput::make('invoice_bic')
                    ->label(__('filament-accounting::fields.bic'))
                    ->maxLength(11)
                    ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! Sepa::isValidBic(filled($value) ? (string) $value : null)) {
                            $fail(__('filament-accounting::validation.bic'));
                        }
                    }),
                TextInput::make('default_payment_terms_days')->label(__('filament-accounting::fields.payment_terms_days'))->numeric()->minValue(0)->required(),
            ])->columns(2),
            Section::make(__('filament-accounting::fields.invoice_layout'))->schema([
                TextInput::make('invoice_logo_path')->label(__('filament-accounting::fields.logo_path')),
                TextInput::make('invoice_template_key')->label(__('filament-accounting::fields.template_key'))->required(),
                TextInput::make('invoice_template_version')->label(__('filament-accounting::fields.template_version'))->required(),
            ])->columns(3),
            Section::make(__('filament-accounting::fields.accounting_settings'))->schema([
                TextInput::make('base_currency')->label(__('filament-accounting::fields.base_currency'))->maxLength(3)->required(),
                TextInput::make('locale')->label(__('filament-accounting::fields.locale')),
                TextInput::make('timezone')->label(__('filament-accounting::fields.timezone')),
                TextInput::make('fiscal_year_start_month')->numeric()->label(__('filament-accounting::fields.fiscal_year_start')),
                TextInput::make('compliance_profile_key')->label(__('filament-accounting::fields.compliance_profile')),
                Select::make('state')->label(__('filament-accounting::fields.state'))->options([
                    LegalEntityState::Active->value => __('filament-accounting::statuses.entity.active'),
                    LegalEntityState::Inactive->value => __('filament-accounting::statuses.entity.inactive'),
                ]),
            ])->columns(2),
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
