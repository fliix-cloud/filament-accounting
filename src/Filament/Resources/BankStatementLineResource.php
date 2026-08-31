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
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Support\BankSourceLinkRegistry;
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
            ->with([
                'bankAccount',
                'reconciliations.splits.openItem.document.party',
                'reconciliations.splits.postingRuleVersion.postingRule',
                'reconciliations.splits.ledgerAccount',
            ])
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
                TextColumn::make('linked_targets')
                    ->label(__('filament-accounting::fields.target'))
                    ->state(fn (BankStatementLine $record): string => self::linkedTargetsSummary($record))
                    ->wrap()
                    ->url(fn (BankStatementLine $record): ?string => $record->activePostedReconciliation() instanceof Reconciliation
                        ? ReconciliationPage::getUrl(['line' => $record->uuid])
                        : null),
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
                SelectFilter::make('direction')
                    ->label(__('filament-accounting::fields.direction'))
                    ->options([
                        'incoming' => __('filament-accounting::fields.incoming'),
                        'outgoing' => __('filament-accounting::fields.outgoing'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'incoming' => $query->where('amount_minor', '>', 0),
                            'outgoing' => $query->where('amount_minor', '<', 0),
                            default => $query,
                        };
                    }),
                SelectFilter::make('reconciliation_state')
                    ->label(__('filament-accounting::fields.reconciliation'))
                    ->options([
                        'unassigned' => __('filament-accounting::statuses.reconciliation.unassigned'),
                        'assigned' => __('filament-accounting::statuses.reconciliation.assigned'),
                        'review' => __('filament-accounting::statuses.reconciliation.review'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'unassigned' => $query->whereDoesntHave('reconciliations', fn (Builder $inner): Builder => $inner->where('status', 'posted')),
                            'assigned' => $query->whereHas('reconciliations', fn (Builder $inner): Builder => $inner->where('status', 'posted')),
                            'review' => $query->where('needs_review', true),
                            default => $query,
                        };
                    }),
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
                Action::make('assign')
                    ->label(__('filament-accounting::actions.assign_directly'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (BankStatementLine $record): bool => ! ($record->activePostedReconciliation() instanceof Reconciliation))
                    ->url(fn (BankStatementLine $record): string => ReconciliationPage::getUrl([
                        'line' => $record->uuid,
                        'mode' => 'direct',
                    ])),
                Action::make('split')
                    ->label(__('filament-accounting::actions.split_transaction'))
                    ->icon('heroicon-o-arrows-pointing-out')
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record): bool => ! ($record->activePostedReconciliation() instanceof Reconciliation))
                    ->url(fn (BankStatementLine $record): string => ReconciliationPage::getUrl([
                        'line' => $record->uuid,
                        'mode' => 'split',
                    ])),
                Action::make('viewAssignment')
                    ->label(__('filament-accounting::actions.view_assignment'))
                    ->visible(fn (BankStatementLine $record): bool => $record->activePostedReconciliation() instanceof Reconciliation)
                    ->url(fn (BankStatementLine $record): string => ReconciliationPage::getUrl(['line' => $record->uuid])),
                Action::make('openSource')
                    ->label(__('filament-accounting::actions.open_source'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (BankStatementLine $record): bool => app(BankSourceLinkRegistry::class)->url($record) !== null)
                    ->url(fn (BankStatementLine $record): ?string => app(BankSourceLinkRegistry::class)->url($record))
                    ->openUrlInNewTab(),
            ]);
    }

    private static function linkedTargetsSummary(BankStatementLine $record): string
    {
        $reconciliation = $record->activePostedReconciliation();
        if (! $reconciliation instanceof Reconciliation) {
            return '—';
        }

        return $reconciliation->splits
            ->map(function (ReconciliationSplit $split): string {
                if ($split->openItem?->document) {
                    return (string) ($split->openItem->document->number
                        ?: $split->openItem->document->supplier_invoice_number
                        ?: $split->openItem->document->uuid);
                }

                if ($split->postingRuleVersion?->postingRule) {
                    return $split->postingRuleVersion->postingRule->label;
                }

                if ($split->ledgerAccount) {
                    return $split->ledgerAccount->code.' · '.$split->ledgerAccount->name;
                }

                return __('filament-accounting::fields.'.$split->purpose->value);
            })
            ->implode(', ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementLines::route('/'),
            'view' => ViewBankStatementLine::route('/{record}'),
        ];
    }
}
