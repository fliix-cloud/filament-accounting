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
use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\EditAccountingBankAccount;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\ListAccountingBankAccounts;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class AccountingBankAccountResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = AccountingBankAccount::class;

    protected static ?string $slug = 'accounting/bank-accounts';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANKING;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.bank_accounts');
    }

    protected static function ability(): string
    {
        return 'manage_chart';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery())->with('ledgerAccount');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->label(__('filament-accounting::fields.display_name'))->required(),
            TextInput::make('iban')->label(__('filament-accounting::fields.iban'))->disabled(),
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->disabled(),
            TextInput::make('driver_key')->label(__('filament-accounting::fields.source'))->disabled(),
            Select::make('ledger_account_id')
                ->label(__('filament-accounting::fields.ledger_account'))
                ->options(function (): array {
                    $entity = app(LegalEntityScope::class)->require();

                    return LedgerAccount::query()
                        ->where('legal_entity_id', $entity->getKey())
                        ->where('is_active', true)
                        ->where('type', AccountType::Asset->value)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (LedgerAccount $account): array => [$account->getKey() => $account->label()])
                        ->all();
                })
                ->searchable()
                ->required(),
            Toggle::make('ledger_mapping_confirmed')
                ->label(__('filament-accounting::fields.confirm_bank_ledger_mapping'))
                ->required(),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_name')->label(__('filament-accounting::fields.display_name'))->searchable(),
            TextColumn::make('iban')->label(__('filament-accounting::fields.iban')),
            TextColumn::make('ledgerAccount.code')->label(__('filament-accounting::fields.ledger_account')),
            IconColumn::make('ledger_mapping_confirmed')->boolean()->label(__('filament-accounting::fields.mapping_confirmed')),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingBankAccounts::route('/'),
            'edit' => EditAccountingBankAccount::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
