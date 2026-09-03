<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ViewBankStatementLine;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class BankStatementLineResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = BankStatementLine::class;

    protected static ?string $slug = 'accounting/bank-transactions';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /** @var class-string<ListBankStatementLines> */
    protected static string $listPage = ListBankStatementLines::class;

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::BANKING;
    }

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
            ->whereHas('bankAccount', fn (Builder $query): Builder => $query->where('is_active', true))
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
        $pendingGray = fn (BankStatementLine $record): ?string => $record->source_status === StatementLineStatus::Pending
            ? 'gray'
            : null;

        return $table
            ->defaultSort('booking_date', 'desc')
            ->defaultGroup('transaction_date')
            ->groupingSettingsHidden()
            ->groups([
                Group::make('transaction_date')
                    ->column('booking_date')
                    ->collapsible()
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (BankStatementLine $record): string => match (true) {
                        $record->source_status === StatementLineStatus::Pending => '__pending__',
                        $record->booking_date !== null => $record->booking_date->toDateString(),
                        default => '__without_booking_date__',
                    })
                    ->getTitleFromRecordUsing(function (BankStatementLine $record, $livewire): string {
                        if ($record->source_status !== StatementLineStatus::Pending) {
                            return $record->booking_date?->translatedFormat('d.m.Y') ?? '—';
                        }

                        $count = 1;
                        $sumMinor = $record->amount_minor;

                        if (is_object($livewire) && method_exists($livewire, 'getFilteredTableQuery')) {
                            $query = $livewire->getFilteredTableQuery();
                            if ($query instanceof Builder) {
                                $summary = (clone $query)
                                    ->where('source_status', StatementLineStatus::Pending->value)
                                    ->toBase()
                                    ->selectRaw('count(*) as pending_count, coalesce(sum(amount_minor), 0) as pending_sum_minor')
                                    ->first();
                                $count = max(1, (int) ($summary->pending_count ?? 0));
                                $sumMinor = (int) ($summary->pending_sum_minor ?? 0);
                            }
                        }

                        return __('filament-accounting::statuses.statement.pending')
                            .' ('.$count.' · '.MoneyFormatter::format($sumMinor, $record->currency).')';
                    })
                    ->groupQueryUsing(fn ($query) => $query
                        ->groupByRaw("case when source_status = 'pending' then 0 else 1 end")
                        ->groupByRaw("case when source_status = 'pending' then null else booking_date end"))
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key): Builder {
                        if ($key === '__pending__') {
                            return $query->where('source_status', StatementLineStatus::Pending->value);
                        }

                        $query->where('source_status', '!=', StatementLineStatus::Pending->value);

                        return $key === '__without_booking_date__'
                            ? $query->whereNull('booking_date')
                            : $query->whereDate('booking_date', $key);
                    })
                    ->orderQueryUsing(fn (Builder $query): Builder => $query
                        ->reorder()
                        ->orderByRaw("case when source_status = 'pending' then 0 else 1 end")
                        ->orderByDesc('booking_date')
                        ->orderByDesc('id')),
            ])
            ->recordClasses(fn (BankStatementLine $record): ?string => $record->source_status === StatementLineStatus::Pending
                ? 'text-gray-500 dark:text-gray-400'
                : null)
            ->columns([
                TextColumn::make('booking_date')->date()->sortable()->label(__('filament-accounting::fields.booking_date'))->color($pendingGray),
                TextColumn::make('value_date')->date()->sortable()->label(__('filament-accounting::fields.value_date'))->color($pendingGray),
                TextColumn::make('counterparty_name')->searchable()->label(__('filament-accounting::fields.counterparty'))->color($pendingGray),
                TextColumn::make('purpose')->searchable()->limit(40)->label(__('filament-accounting::fields.purpose'))->color($pendingGray),
                TextColumn::make('amount_minor')
                    ->label(__('filament-accounting::fields.amount'))
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn ($state, BankStatementLine $record): string => MoneyFormatter::format((int) $state, $record->currency))
                    ->color(fn (BankStatementLine $record): string => match (true) {
                        $record->source_status === StatementLineStatus::Pending => 'gray',
                        $record->amount_minor < 0 => 'accounting-negative',
                        $record->amount_minor > 0 => 'accounting-positive',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('source_status')
                    ->badge()
                    ->label(__('filament-accounting::fields.source_status'))
                    ->formatStateUsing(fn (StatementLineStatus $state): string => __('filament-accounting::statuses.statement.'.$state->value)),
                TextColumn::make('reconciliation_badge')
                    ->label(__('filament-accounting::fields.reconciliation'))
                    ->badge()
                    ->state(fn (BankStatementLine $record): string => __('filament-accounting::statuses.reconciliation.'.$record->derivedBadge()->value)),
                TextColumn::make('amount_match')
                    ->label(__('filament-accounting::fields.amount_match'))
                    ->badge()
                    ->color(fn (BankStatementLine $record): string => match ($record->assignedAmountMatches()) {
                        true => 'success',
                        false => 'warning',
                        default => 'gray',
                    })
                    ->state(fn (BankStatementLine $record): ?string => match ($record->assignedAmountMatches()) {
                        true => __('filament-accounting::statuses.amount_match.matched'),
                        false => __('filament-accounting::statuses.amount_match.mismatch'),
                        default => null,
                    })
                    ->placeholder('—'),
                TextColumn::make('linked_targets')
                    ->label(__('filament-accounting::fields.target'))
                    ->state(fn (BankStatementLine $record): string => self::linkedTargetsSummary($record))
                    ->wrap()
                    ->url(fn (BankStatementLine $record): ?string => $record->activePostedReconciliation() instanceof Reconciliation
                        ? ReconciliationPage::getUrl(['line' => $record->uuid])
                        : null),
            ])
            ->filters([
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
                Action::make('reconcile')
                    ->label(__('filament-accounting::actions.reconcile'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (BankStatementLine $record): bool => ! ($record->activePostedReconciliation() instanceof Reconciliation))
                    ->modalHeading(__('filament-accounting::fields.reconciliation_assistant'))
                    ->modalDescription(__('filament-accounting::fields.reconciliation_assistant_help'))
                    ->modalWidth(Width::ScreenTwoExtraLarge)
                    ->stickyModalHeader()
                    ->closeModalByClickingAway(false)
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false)
                    ->modalContent(fn (BankStatementLine $record): View => view(
                        'filament-accounting::components.reconciliation-modal',
                        [
                            'line' => $record->uuid,
                            'fallbackUrl' => ReconciliationPage::getUrl(['line' => $record->uuid]),
                        ],
                    )),
                Action::make('viewAssignment')
                    ->label(__('filament-accounting::actions.view_assignment'))
                    ->visible(fn (BankStatementLine $record): bool => $record->activePostedReconciliation() instanceof Reconciliation)
                    ->url(fn (BankStatementLine $record): string => ReconciliationPage::getUrl(['line' => $record->uuid])),
            ])
            ->emptyStateHeading(function ($livewire): string {
                if ($livewire instanceof ListBankStatementLines && ! $livewire->hasSelectedAccount()) {
                    return __('filament-accounting::fields.select_account');
                }

                return __('filament-tables::table.empty.heading', [
                    'model' => static::getPluralModelLabel(),
                ]);
            })
            ->emptyStateDescription(function ($livewire): ?string {
                if ($livewire instanceof ListBankStatementLines && ! $livewire->hasSelectedAccount()) {
                    return __('filament-accounting::fields.select_account_help');
                }

                return null;
            })
            ->paginated([25, 50, 100]);
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
        $listPage = static::$listPage;

        return [
            'index' => $listPage::route('/'),
            'view' => ViewBankStatementLine::route('/{record}'),
        ];
    }

    /**
     * @param  class-string<ListBankStatementLines>  $page
     */
    public static function listPageUsing(string $page): void
    {
        if ($page !== ListBankStatementLines::class && ! is_subclass_of($page, ListBankStatementLines::class)) {
            throw new \InvalidArgumentException('The bank transaction list page must extend '.ListBankStatementLines::class.'.');
        }

        static::$listPage = $page;
    }

    /** @return class-string<ListBankStatementLines> */
    public static function getListPage(): string
    {
        return static::$listPage;
    }
}
