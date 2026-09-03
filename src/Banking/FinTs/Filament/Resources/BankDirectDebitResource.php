<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitSequenceType;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages\CreateBankDirectDebit;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages\ListBankDirectDebits;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages\ViewBankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\CapabilityService;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Builder;

class BankDirectDebitResource extends Resource
{
    protected static ?string $model = BankDirectDebit::class;

    protected static ?string $slug = 'bank/direct-debits';

    protected static ?int $navigationSort = 45;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

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
        return __('filament-accounting::banking/fints/navigation.direct_debits');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.direct_debit.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->with(['account', 'creditorProfile', 'mandate']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('creditor_profile_id')
                ->label(__('filament-accounting::banking/fints/fields.creditor_profile'))
                ->options(fn (): array => DirectDebitCreditorProfile::query()
                    ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (DirectDebitCreditorProfile $profile): array => [$profile->id => $profile->label()])
                    ->all())
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('direct_debit_mandate_id', null);
                    $set('accounting_bank_account_id', null);
                    $set('sequence_type', null);
                })
                ->required(),
            Select::make('direct_debit_mandate_id')
                ->label(__('filament-accounting::banking/fints/fields.mandate'))
                ->options(function (Get $get): array {
                    $profileId = $get('creditor_profile_id');
                    if (filled($profileId) === false) {
                        return [];
                    }

                    return DirectDebitMandate::query()
                        ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
                        ->where('creditor_profile_id', $profileId)
                        ->where('status', DirectDebitMandateStatus::Active->value)
                        ->orderBy('reference')
                        ->get()
                        ->mapWithKeys(fn (DirectDebitMandate $mandate): array => [$mandate->id => $mandate->label()])
                        ->all();
                })
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    $set('accounting_bank_account_id', null);
                    $mandate = DirectDebitMandate::query()
                        ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
                        ->find($state);

                    if (($mandate instanceof DirectDebitMandate) === false) {
                        $set('sequence_type', null);

                        return;
                    }

                    $set('sequence_type', $mandate->nextSequenceType()->value);
                })
                ->required(),
            Select::make('accounting_bank_account_id')
                ->label(__('filament-accounting::banking/fints/fields.source_account'))
                ->options(fn (Get $get): array => self::accountOptionsForMandate($get('direct_debit_mandate_id')))
                ->required(),
            Select::make('sequence_type')
                ->label(__('filament-accounting::banking/fints/fields.sequence_type'))
                ->options(fn (Get $get): array => self::sequenceOptions($get('direct_debit_mandate_id')))
                ->required(),
            TextInput::make('amount')
                ->numeric()
                ->required()
                ->label(__('filament-accounting::banking/fints/fields.amount')),
            TextInput::make('purpose')
                ->label(__('filament-accounting::banking/fints/fields.purpose'))
                ->maxLength(140),
            DatePicker::make('requested_collection_date')
                ->label(__('filament-accounting::banking/fints/fields.collection_date'))
                ->helperText(__('filament-accounting::banking/fints/fields.collection_date_help'))
                ->minDate(today())
                ->required(),
            TextInput::make('end_to_end_id')
                ->label(__('filament-accounting::banking/fints/fields.end_to_end_id'))
                ->helperText(__('filament-accounting::banking/fints/fields.end_to_end_id_help'))
                ->maxLength(35),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime(),
                TextColumn::make('mandate_id')->label(__('filament-accounting::banking/fints/fields.mandate')),
                TextColumn::make('debtor_name')->label(__('filament-accounting::banking/fints/fields.debtor_name')),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('scheme')->badge(),
                TextColumn::make('sequence_type')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankDirectDebits::route('/'),
            'create' => CreateBankDirectDebit::route('/create'),
            'view' => ViewBankDirectDebit::route('/{record}'),
        ];
    }

    /** @return array<int|string, string> */
    private static function accountOptionsForMandate(mixed $state): array
    {
        $mandate = DirectDebitMandate::query()
            ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
            ->find($state);

        if (($mandate instanceof DirectDebitMandate) === false) {
            return [];
        }

        $capabilities = app(CapabilityService::class);

        return BankAccount::query()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->where('is_available', true)
            ->where('is_enabled', true)
            ->with('connection')
            ->get()
            ->filter(function (BankAccount $account) use ($capabilities, $mandate): bool {
                $connection = $account->connection;

                return $connection instanceof BankConnection
                    && $capabilities->supportsDirectDebitScheme($connection, $mandate->scheme);
            })
            ->mapWithKeys(fn (BankAccount $account): array => [$account->id => $account->displayName()])
            ->all();
    }

    /** @return array<string, string> */
    private static function sequenceOptions(mixed $state): array
    {
        $mandate = DirectDebitMandate::query()
            ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
            ->find($state);

        if (($mandate instanceof DirectDebitMandate) === false) {
            return [];
        }

        $next = $mandate->nextSequenceType();
        $options = [$next->value => $next->getLabel()];

        if ($next === DirectDebitSequenceType::Recurring) {
            $options[DirectDebitSequenceType::Final->value] = DirectDebitSequenceType::Final->getLabel();
        }

        return $options;
    }
}
