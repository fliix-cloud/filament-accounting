<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ViewBankStatementLine;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;

class BankStatementLineResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = BankStatementLine::class;

    protected static ?string $slug = 'accounting/bank-transactions';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.bank_transactions');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.bank_statement_line.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.bank_statement_line.plural');
    }

    protected static function ability(): string
    {
        return 'view_bank';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['bankAccount', 'reconciliations.splits'])
            ->orderByDesc('booking_date');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_date')->date()->sortable()->label(__('filament-accounting::fields.booking_date')),
                TextColumn::make('bankAccount.display_name')->label(__('filament-accounting::fields.account')),
                TextColumn::make('counterparty_name')->searchable()->label(__('filament-accounting::fields.counterparty')),
                TextColumn::make('purpose')->searchable()->limit(40)->label(__('filament-accounting::fields.purpose')),
                TextColumn::make('amount_minor')
                    ->label(__('filament-accounting::fields.amount'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, BankStatementLine $record): string => MoneyFormatter::format((int) $state, $record->currency)),
                TextColumn::make('source_status')->badge()->label(__('filament-accounting::fields.source_status')),
                TextColumn::make('reconciliation_badge')
                    ->label(__('filament-accounting::fields.reconciliation'))
                    ->badge()
                    ->state(fn (BankStatementLine $record): string => __('filament-accounting::statuses.reconciliation.'.$record->derivedBadge()->value)),
            ])
            ->filters([
                SelectFilter::make('bank_account_id')
                    ->label(__('filament-accounting::fields.account'))
                    ->options(fn (): array => AccountingBankAccount::query()->pluck('display_name', 'id')->all()),
                SelectFilter::make('source_status')
                    ->label(__('filament-accounting::fields.source_status'))
                    ->options([
                        'pending' => __('filament-accounting::statuses.statement.pending'),
                        'booked' => __('filament-accounting::statuses.statement.booked'),
                        'storno' => __('filament-accounting::statuses.statement.storno'),
                    ]),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label(__('filament-accounting::fields.from')),
                        DatePicker::make('to')->label(__('filament-accounting::fields.to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('booking_date', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('booking_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('reconcile')
                    ->label(__('filament-accounting::actions.reconcile'))
                    ->url(fn (BankStatementLine $record): string => ReconciliationPage::getUrl(['line' => $record->uuid])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementLines::route('/'),
            'view' => ViewBankStatementLine::route('/{record}'),
        ];
    }
}
