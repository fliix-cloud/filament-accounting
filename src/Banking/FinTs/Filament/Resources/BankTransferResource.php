<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Filament\Pages\StrongAuthentication;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages\CreateBankTransfer;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages\ListBankTransfers;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages\ViewBankTransfer;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\CapabilityService;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Builder;

class BankTransferResource extends Resource
{
    protected static ?string $model = BankTransfer::class;

    protected static ?string $slug = 'bank/transfers';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

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
        return __('filament-accounting::banking/fints/navigation.transfers');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.transfer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.transfer.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->with('account');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('filament-accounting::banking/fints/fields.transfer_type'))
                ->options(self::transferTypeOptions())
                ->default(TransferType::Sepa->value)
                ->live()
                ->required(),
            Select::make('accounting_bank_account_id')
                ->label(__('filament-accounting::banking/fints/fields.source_account'))
                ->required()
                ->options(fn (Get $get): array => self::accountOptionsForType($get('type'))),
            TextInput::make('recipient_name')->label(__('filament-accounting::banking/fints/fields.recipient_name'))->required(),
            TextInput::make('recipient_iban')->label(__('filament-accounting::banking/fints/fields.recipient_iban'))->required(),
            TextInput::make('recipient_bic')->label(__('filament-accounting::banking/fints/fields.bic')),
            TextInput::make('amount')->numeric()->label(__('filament-accounting::banking/fints/fields.amount'))->required(),
            TextInput::make('currency')->default('EUR')->maxLength(3)->label(__('filament-accounting::banking/fints/fields.currency')),
            TextInput::make('purpose')->label(__('filament-accounting::banking/fints/fields.purpose')),
            DatePicker::make('requested_execution_date')->label(__('filament-accounting::banking/fints/fields.execution_date')),
            TextInput::make('end_to_end_id')->label(__('filament-accounting::banking/fints/fields.end_to_end_id')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->label(__('filament-accounting::banking/fints/fields.submitted_at')),
                TextColumn::make('account.iban')->formatStateUsing(fn ($state, BankTransfer $r) => $r->account?->maskedIban())->label(__('filament-accounting::banking/fints/fields.source_account')),
                TextColumn::make('recipient_name')->label(__('filament-accounting::banking/fints/fields.recipient_name')),
                TextColumn::make('recipient_iban')->formatStateUsing(fn ($state, BankTransfer $r) => $r->maskedRecipientIban()),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status'),
                SelectFilter::make('type'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resumeSca')
                    ->label(__('filament-accounting::banking/fints/actions.resume_sca'))
                    ->visible(fn (BankTransfer $record): bool => $record->status->isInteractive())
                    ->url(fn (BankTransfer $record): ?string => self::resumeScaUrl($record)),
                DeleteAction::make()
                    ->visible(fn (BankTransfer $record): bool => $record->status->isDeletable()),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransfers::route('/'),
            'create' => CreateBankTransfer::route('/create'),
            'view' => ViewBankTransfer::route('/{record}'),
        ];
    }

    public static function resumeScaUrl(BankTransfer $record): ?string
    {
        $session = StrongAuthenticationSession::openFor($record);

        return $session instanceof StrongAuthenticationSession
            ? StrongAuthentication::getUrl(['record' => $session])
            : null;
    }

    /** @return array<string, string> */
    private static function transferTypeOptions(): array
    {
        $options = [];

        if ((bool) config('filament-accounting.banking.fints.features.transfers', false)) {
            $options[TransferType::Sepa->value] = TransferType::Sepa->getLabel();
        }

        if ((bool) config('filament-accounting.banking.fints.features.realtime_transfers', false)) {
            $options[TransferType::Realtime->value] = TransferType::Realtime->getLabel();
        }

        // International transfers remain an extension point until the package
        // has a dedicated action/capability implementation for them.
        return $options;
    }

    /** @return array<int|string, string> */
    private static function accountOptionsForType(mixed $state): array
    {
        $type = is_string($state) ? TransferType::tryFrom($state) : null;
        if ($type === null) {
            return [];
        }

        $capabilities = app(CapabilityService::class);

        return BankAccount::query()
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->where('is_available', true)
            ->where('is_enabled', true)
            ->with('connection')
            ->get()
            ->filter(function (BankAccount $account) use ($capabilities, $type): bool {
                $connection = $account->connection;

                return $connection instanceof BankConnection
                    && $capabilities->supportsTransferType($connection, $type);
            })
            ->mapWithKeys(fn (BankAccount $account): array => [$account->id => $account->displayName()])
            ->all();
    }
}
