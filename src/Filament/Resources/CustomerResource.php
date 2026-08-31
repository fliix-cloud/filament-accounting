<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\PartyMandateScheme;
use FilamentAccounting\Enums\PartyMandateStatus;
use FilamentAccounting\Enums\PartyMandateType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\EditCustomer;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\ListCustomers;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Party::class;

    protected static ?string $slug = 'accounting/customers';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::SALES;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.customers');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.customer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.customer.plural');
    }

    protected static function ability(): string
    {
        return 'manage_parties';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('is_customer', true)
            ->with('bankAccounts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->required(),
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name')),
            TextInput::make('email')->label(__('filament-accounting::fields.email'))->email(),
            TextInput::make('phone')->label(__('filament-accounting::fields.phone')),
            TextInput::make('country_code')->label(__('filament-accounting::fields.country'))->maxLength(2),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
            Section::make(__('filament-accounting::fields.bank_accounts'))
                ->description(__('filament-accounting::fields.bank_accounts_help'))
                ->schema([
                    Repeater::make('bankAccounts')
                        ->label(__('filament-accounting::fields.bank_accounts'))
                        ->relationship()
                        ->schema(self::bankAccountSchema())
                        ->itemLabel(fn (array $state): ?string => filled($state['mandate_reference'] ?? null)
                            ? (string) $state['mandate_reference'].' · '.($state['iban'] ?? '')
                            : ($state['iban'] ?? null))
                        ->collapsible()
                        ->defaultItems(0)
                        ->addActionLabel(__('filament-accounting::actions.add_bank_account')),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')->label(__('filament-accounting::fields.legal_name'))->searchable(),
                TextColumn::make('email')->label(__('filament-accounting::fields.email')),
                TextColumn::make('primary_iban')
                    ->label(__('filament-accounting::fields.iban'))
                    ->state(fn (Party $record): ?string => $record->bankAccounts
                        ->sortByDesc('is_primary')
                        ->first()
                        ?->iban),
                TextColumn::make('mandate_references')
                    ->label(__('filament-accounting::fields.mandate_reference'))
                    ->state(fn (Party $record): string => $record->bankAccounts
                        ->pluck('mandate_reference')
                        ->filter()
                        ->implode(', ') ?: '—'),
                IconColumn::make('is_active')->boolean()->label(__('filament-accounting::fields.is_active')),
            ])
            ->defaultSort('legal_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return app(AccountingAuthorizer::class)->can('manage_parties');
    }

    /** @return list<Component> */
    private static function bankAccountSchema(): array
    {
        return [
            TextInput::make('holder_name')
                ->label(__('filament-accounting::fields.account_holder'))
                ->maxLength(255),
            TextInput::make('iban')
                ->label(__('filament-accounting::fields.iban'))
                ->required()
                ->maxLength(34)
                ->rule(function (): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! Sepa::isValidIban((string) $value)) {
                            $fail(__('filament-accounting::validation.iban'));
                        }
                    };
                }),
            TextInput::make('bic')
                ->label(__('filament-accounting::fields.bic'))
                ->maxLength(11)
                ->rule(function (): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! Sepa::isValidBic(filled($value) ? (string) $value : null)) {
                            $fail(__('filament-accounting::validation.bic'));
                        }
                    };
                }),
            Toggle::make('is_primary')
                ->label(__('filament-accounting::fields.primary_bank_account'))
                ->default(false),
            TextInput::make('mandate_reference')
                ->label(__('filament-accounting::fields.mandate_reference'))
                ->helperText(__('filament-accounting::fields.mandate_reference_help'))
                ->maxLength(35)
                ->live()
                ->rule(function (): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! Sepa::isValidMandateReference(filled($value) ? (string) $value : null)) {
                            $fail(__('filament-accounting::validation.mandate_reference'));
                        }
                    };
                }),
            DatePicker::make('mandate_signed_on')
                ->label(__('filament-accounting::fields.mandate_signed_on'))
                ->visible(fn (Get $get): bool => filled($get('mandate_reference')))
                ->required(fn (Get $get): bool => filled($get('mandate_reference'))),
            Select::make('mandate_scheme')
                ->label(__('filament-accounting::fields.mandate_scheme'))
                ->options([
                    PartyMandateScheme::Core->value => __('filament-accounting::statuses.mandate_scheme.CORE'),
                    PartyMandateScheme::B2b->value => __('filament-accounting::statuses.mandate_scheme.B2B'),
                ])
                ->default(PartyMandateScheme::Core->value)
                ->visible(fn (Get $get): bool => filled($get('mandate_reference'))),
            Select::make('mandate_type')
                ->label(__('filament-accounting::fields.mandate_type'))
                ->options([
                    PartyMandateType::OneOff->value => __('filament-accounting::statuses.mandate_type.one_off'),
                    PartyMandateType::Recurring->value => __('filament-accounting::statuses.mandate_type.recurring'),
                ])
                ->default(PartyMandateType::Recurring->value)
                ->visible(fn (Get $get): bool => filled($get('mandate_reference'))),
            Select::make('mandate_status')
                ->label(__('filament-accounting::fields.mandate_status'))
                ->options([
                    PartyMandateStatus::Active->value => __('filament-accounting::statuses.mandate_status.active'),
                    PartyMandateStatus::Revoked->value => __('filament-accounting::statuses.mandate_status.revoked'),
                    PartyMandateStatus::Closed->value => __('filament-accounting::statuses.mandate_status.closed'),
                ])
                ->default(PartyMandateStatus::Active->value)
                ->visible(fn (Get $get): bool => filled($get('mandate_reference'))),
        ];
    }
}
