<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Enums\BankConnectionStatus;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages\CreateBankConnection;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages\EditBankConnection;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages\ListBankConnections;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankInstitute;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Support\BankQuirks;
use FilamentAccounting\Banking\FinTs\Support\ProductRegistration;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Builder;

class BankConnectionResource extends Resource
{
    protected static ?string $model = BankConnection::class;

    protected static ?string $slug = 'bank/settings';

    protected static ?int $navigationSort = 50;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

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
        return __('filament-accounting::banking/fints/navigation.settings');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.connection.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::banking/fints/resources.connection.plural');
    }

    /** @return Builder<BankConnection> */
    public static function getEloquentQuery(): Builder
    {
        return app(OwnerScope::class)->connections();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('filament-accounting::banking/fints/notifications.product_id_missing'))
                ->description(__('filament-accounting::banking/fints/notifications.product_id_missing_help'))
                ->warning()
                ->visible(fn (): bool => ! ProductRegistration::isConfigured()),
            Section::make(__('filament-accounting::banking/fints/fields.institute_section'))
                ->description(__('filament-accounting::banking/fints/fields.institute_help'))
                ->schema([
                    Select::make('institute_id')
                        ->label(__('filament-accounting::banking/fints/fields.institute'))
                        ->placeholder(__('filament-accounting::banking/fints/fields.institute_placeholder'))
                        ->searchable()
                        ->preload(false)
                        ->dehydrated(false)
                        ->visibleOn(Operation::Create)
                        ->getSearchResultsUsing(function (string $search): array {
                            return BankInstitute::query()
                                ->withPinTan()
                                ->search($search)
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (BankInstitute $institute): array => [
                                    (string) $institute->id => $institute->label(),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            $institute = BankInstitute::query()->find($value);

                            return $institute instanceof BankInstitute ? $institute->label() : null;
                        })
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $institute = BankInstitute::query()->find($state);

                            if (! $institute instanceof BankInstitute) {
                                return;
                            }
                            $url = (string) $institute->pin_tan_url;
                            $set('display_name', $institute->name);
                            $set('bank_code', BankQuirks::normalizeBankCode((string) $institute->bank_code, $url));
                            $set('endpoint_url', $url);
                        }),
                    TextInput::make('display_name')->label(__('filament-accounting::banking/fints/fields.display_name'))->required()->maxLength(255),
                    TextInput::make('bank_code')->label(__('filament-accounting::banking/fints/fields.bank_code'))->required()->maxLength(16),
                    TextInput::make('endpoint_url')->label(__('filament-accounting::banking/fints/fields.endpoint_url'))->required()->url(),
                ]),
            Section::make(__('filament-accounting::banking/fints/fields.credentials_section'))
                ->schema([
                    TextInput::make('username')->label(__('filament-accounting::banking/fints/fields.username'))->required()->maxLength(255),
                    TextInput::make('pin')
                        ->label(__('filament-accounting::banking/fints/fields.pin'))
                        ->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === Operation::Create->value || $operation === 'create')
                        ->helperText(__('filament-accounting::banking/fints/fields.pin_keep')),
                    TextInput::make('customer_id')->label(__('filament-accounting::banking/fints/fields.customer_id'))->maxLength(255),
                ]),
            Callout::make(fn (?BankConnection $record): string => $record instanceof BankConnection ? (string) $record->last_error_message : '')
                ->danger()
                ->visible(fn (?BankConnection $record): bool => $record instanceof BankConnection
                    && $record->status === BankConnectionStatus::Error
                    && filled($record->last_error_message)),
            Section::make(__('filament-accounting::banking/fints/fields.tan_section'))
                ->description(__('filament-accounting::banking/fints/fields.tan_help'))
                ->visibleOn(Operation::Edit)
                ->schema([
                    Select::make('tan_mode_id')
                        ->label(__('filament-accounting::banking/fints/fields.tan_mode'))
                        ->options(fn (?BankConnection $record): array => $record instanceof BankConnection ? $record->tanModeChoices() : [])
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set, ?BankConnection $record): void {
                            $set('tan_medium_name', null);
                            if (! $record instanceof BankConnection || ! filled($state)) {
                                return;
                            }
                            $media = $record->tanMediaChoices((string) $state);
                            if (count($media) === 1) {
                                $set('tan_medium_name', array_key_first($media));
                            }
                        }),
                    Select::make('tan_medium_name')
                        ->label(__('filament-accounting::banking/fints/fields.tan_medium'))
                        ->helperText(__('filament-accounting::banking/fints/fields.tan_medium_help'))
                        ->options(function (Get $get, ?BankConnection $record): array {
                            if (! $record instanceof BankConnection) {
                                return [];
                            }

                            $modeId = $get('tan_mode_id');

                            return $record->tanMediaChoices(filled($modeId) ? (string) $modeId : null);
                        })
                        ->searchable()
                        ->native(false)
                        ->visible(function (Get $get, ?BankConnection $record): bool {
                            if (! $record instanceof BankConnection) {
                                return false;
                            }
                            $modeId = $get('tan_mode_id');

                            return $record->tanModeNeedsMedium(filled($modeId) ? (string) $modeId : null);
                        })
                        ->required(function (Get $get, ?BankConnection $record): bool {
                            if (! $record instanceof BankConnection) {
                                return false;
                            }
                            $modeId = $get('tan_mode_id');

                            return $record->tanModeNeedsMedium(filled($modeId) ? (string) $modeId : null);
                        }),
                ]),
            Section::make(__('filament-accounting::banking/fints/fields.accounts_section'))
                ->description(__('filament-accounting::banking/fints/fields.accounts_help'))
                ->visibleOn(Operation::Edit)
                ->visible(fn (?BankConnection $record): bool => $record instanceof BankConnection && $record->accounts()->where('is_available', true)->exists())
                ->schema([
                    CheckboxList::make('active_account_ids')
                        ->hiddenLabel()
                        ->allowHtml()
                        ->columns(1)
                        ->options(function (?BankConnection $record): array {
                            if (! $record instanceof BankConnection) {
                                return [];
                            }

                            $options = [];
                            foreach ($record->accounts()->where('is_available', true)->orderBy('iban')->get() as $account) {
                                if (! $account instanceof BankAccount) {
                                    continue;
                                }
                                $html = '<span style="display:block;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;letter-spacing:-0.02em;">'.e($account->maskedIban()).'</span>';
                                $name = $account->display_name ?: $account->product_name;
                                if (filled($name)) {
                                    $html .= '<span style="display:block;font-weight:400;color:#6b7280;">'.e((string) $name).'</span>';
                                }
                                $options[(string) $account->id] = $html;
                            }

                            return $options;
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label(__('filament-accounting::banking/fints/fields.display_name'))->searchable(),
                TextColumn::make('bank_code')->label(__('filament-accounting::banking/fints/fields.bank_code')),
                TextColumn::make('status')->badge()->label(__('filament-accounting::banking/fints/fields.status')),
                TextColumn::make('last_successful_connection_at')->dateTime()->label(__('filament-accounting::banking/fints/fields.last_sync')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankConnections::route('/'),
            'create' => CreateBankConnection::route('/create'),
            'edit' => EditBankConnection::route('/{record}/edit'),
        ];
    }
}
