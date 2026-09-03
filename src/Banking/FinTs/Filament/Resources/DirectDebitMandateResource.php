<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateType;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages\CreateDirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages\EditDirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages\ListDirectDebitMandates;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Models\PartyBankAccount;
use Illuminate\Database\Eloquent\Builder;

class DirectDebitMandateResource extends Resource
{
    protected static ?string $model = DirectDebitMandate::class;

    protected static ?string $slug = 'bank/direct-debit-mandates';

    protected static ?int $navigationSort = 43;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationGroup(): ?string
    {
        return __('filament-accounting::navigation.group');
    }

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANKING;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::banking/fints/navigation.direct_debit_mandates');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit_mandate.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit_mandate.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
            ->with(['creditorProfile', 'partyBankAccount.party']);
    }

    public static function form(Schema $schema): Schema
    {
        $identityLocked = fn (?DirectDebitMandate $record): bool => $record?->first_used_at !== null;

        return $schema->components([
            Select::make('creditor_profile_id')
                ->label(__('filament-accounting::banking/fints/fields.creditor_profile'))
                ->options(fn (): array => DirectDebitCreditorProfile::query()
                    ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (DirectDebitCreditorProfile $profile): array => [$profile->id => $profile->label()])
                    ->all())
                ->disabled($identityLocked)
                ->required(),
            Select::make('party_bank_account_id')
                ->label(__('filament-accounting::banking/fints/fields.debtor_iban'))
                ->options(fn (): array => PartyBankAccount::query()
                    ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
                    ->with('party')
                    ->orderBy('iban')
                    ->get()
                    ->mapWithKeys(fn (PartyBankAccount $account): array => [
                        $account->id => $account->party->displayLabel().' · '.$account->label(),
                    ])->all())
                ->disabled($identityLocked)
                ->searchable()
                ->required(),
            TextInput::make('reference')
                ->label(__('filament-accounting::banking/fints/fields.mandate_id'))
                ->helperText(__('filament-accounting::banking/fints/fields.mandate_reference_help'))
                ->disabled($identityLocked)
                ->required()
                ->maxLength(35),
            Select::make('scheme')
                ->label(__('filament-accounting::banking/fints/fields.scheme'))
                ->options(collect(DirectDebitScheme::cases())->mapWithKeys(
                    fn (DirectDebitScheme $case): array => [$case->value => $case->getLabel()]
                ))
                ->default(DirectDebitScheme::Core->value)
                ->disabled($identityLocked)
                ->live()
                ->required(),
            Select::make('mandate_type')
                ->label(__('filament-accounting::banking/fints/fields.mandate_type'))
                ->options(collect(DirectDebitMandateType::cases())->mapWithKeys(
                    fn (DirectDebitMandateType $case): array => [$case->value => $case->getLabel()]
                ))
                ->default(DirectDebitMandateType::OneOff->value)
                ->disabled($identityLocked)
                ->required(),
            DatePicker::make('signed_on')
                ->label(__('filament-accounting::banking/fints/fields.mandate_signed_on'))
                ->disabled($identityLocked)
                ->required(),
            DateTimePicker::make('debtor_bank_confirmed_at')
                ->label(__('filament-accounting::banking/fints/fields.debtor_bank_confirmed_at'))
                ->helperText(__('filament-accounting::banking/fints/fields.debtor_bank_confirmed_help'))
                ->visible(fn (Get $get): bool => $get('scheme') === DirectDebitScheme::B2b->value),
            Select::make('status')
                ->label(__('filament-accounting::banking/fints/fields.status'))
                ->options(collect(DirectDebitMandateStatus::cases())->mapWithKeys(
                    fn (DirectDebitMandateStatus $case): array => [$case->value => $case->getLabel()]
                ))
                ->default(DirectDebitMandateStatus::Active->value)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('filament-accounting::banking/fints/fields.mandate_id'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('debtor_name')
                    ->label(__('filament-accounting::banking/fints/fields.debtor_name'))
                    ->searchable(),
                TextColumn::make('debtor_iban')
                    ->label(__('filament-accounting::banking/fints/fields.debtor_iban'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('debtor_bic')
                    ->label(__('filament-accounting::banking/fints/fields.bic'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creditorProfile.name')
                    ->label(__('filament-accounting::banking/fints/fields.creditor_profile')),
                TextColumn::make('scheme')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('signed_on')
                    ->date()
                    ->label(__('filament-accounting::banking/fints/fields.mandate_signed_on'))
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectDebitMandates::route('/'),
            'create' => CreateDirectDebitMandate::route('/create'),
            'edit' => EditDirectDebitMandate::route('/{record}/edit'),
        ];
    }
}
