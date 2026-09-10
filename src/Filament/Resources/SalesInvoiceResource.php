<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\CreateSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\EditSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ViewSalesInvoice;
use FilamentAccounting\Filament\Support\InvoiceInfolist;
use FilamentAccounting\Filament\Support\SalesInvoiceCompletionAction;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Support\DecimalInput;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Support\MoneyFormatter;
use FilamentAccounting\Support\ReferenceData;
use FilamentAccounting\Support\RichText;
use FilamentAccounting\Tax\Data\SalesTaxSuggestion;
use FilamentAccounting\Tax\SalesTaxSuggestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Component;

class SalesInvoiceResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'accounting/sales-invoices';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.sales_invoices');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.sales_invoice.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.sales_invoice.plural');
    }

    protected static function ability(): string
    {
        return 'create_draft_invoices';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery())
            ->where('type', DocumentType::SalesInvoice)
            ->with(['party', 'lines.document', 'attachments', 'artifactSet:id,document_id,completed_at', 'openItem.settlements', 'settlements.reconciliation.statementLine'])
            ->with(['correction', 'correctedDocument'])
            ->withCount('settlements');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label(__('filament-accounting::fields.customer'))
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::resetTaxConfirmations($get, $set);
                    self::updateDueDate($get, $set);
                })
                ->options(fn (): array => Party::query()
                    ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                    ->where('is_customer', true)
                    ->where('is_active', true)
                    ->orderBy('legal_name')
                    ->pluck('legal_name', 'id')
                    ->all())
                ->required(),
            DatePicker::make('issue_date')->label(__('filament-accounting::fields.issue_date'))->required()
                ->default(fn (): string => now()->toDateString())
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::resetTaxConfirmations($get, $set);
                    self::updateDueDate($get, $set);
                }),
            Grid::make(3)->schema([
                DatePicker::make('supply_date')->label(__('filament-accounting::fields.supply_date'))
                    ->default(fn (): string => now()->toDateString())
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::resetTaxConfirmations($get, $set)),
                DatePicker::make('due_date')->label(__('filament-accounting::fields.due_date'))
                    ->default(fn (): string => now()->addDays(7)->toDateString()),
                Select::make('currency')
                    ->label(__('filament-accounting::fields.currency'))
                    ->options(function (): array {
                        $currency = (string) app(LegalEntityScope::class)->require()->base_currency;

                        return [$currency => $currency];
                    })
                    ->default(fn (): string => (string) app(LegalEntityScope::class)->require()->base_currency)
                    ->required(),
            ])->columnSpanFull(),
            self::totalsSection(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    Hidden::make('discount'),
                    Hidden::make('account_role'),
                    Hidden::make('ledger_account_id'),
                    Hidden::make('service_from'),
                    Hidden::make('service_to'),
                    Select::make('catalog_item_id')
                        ->label(__('filament-accounting::fields.catalog_item'))
                        ->placeholder(__('filament-accounting::fields.choose_catalog_item'))
                        ->options(fn (): array => CatalogItem::query()
                            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get(['id', 'sku', 'name'])
                            ->mapWithKeys(fn (CatalogItem $item): array => [
                                $item->getKey() => filled($item->sku) ? $item->sku.' - '.$item->name : $item->name,
                            ])
                            ->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('tax_confirmed', false);
                            $item = CatalogItem::query()
                                ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                                ->whereKey($state)
                                ->first();

                            if (! $item instanceof CatalogItem) {
                                return;
                            }

                            $set('description', RichText::sanitize($item->description));
                            $set('quantity', $item->default_quantity);
                            $set('unit', $item->unit);
                            $set('unit_price', ExactMoney::ofMinor((int) $item->default_unit_price_minor, (string) $item->currency)->decimalString());

                            $set('tax_code', $item->default_tax_code);
                        })
                        ->columnSpan(5),
                    TextInput::make('quantity')->label(__('filament-accounting::fields.quantity'))
                        ->inputMode('decimal')->rules([DecimalInput::RULE])->live(onBlur: true)->required()->columnSpan(2),
                    TextInput::make('unit_price')->label(__('filament-accounting::fields.unit_price'))
                        ->inputMode('decimal')->rules([DecimalInput::RULE])->live(onBlur: true)->required()->columnSpan(2),
                    Placeholder::make('line_total')->label(__('filament-accounting::fields.line_total'))
                        ->content(fn (Get $get): string => self::lineTotal($get))->columnSpan(3),
                    Select::make('unit')
                        ->label(__('filament-accounting::fields.unit'))
                        ->placeholder(__('filament-accounting::fields.choose_unit'))
                        ->options(fn (Get $get): array => ReferenceData::catalogUnits($get('unit')))
                        ->searchable()
                        ->columnSpan(4),
                    Select::make('tax_code')
                        ->label(__('filament-accounting::fields.tax_treatment'))
                        ->placeholder(__('filament-accounting::fields.choose_tax'))
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('tax_confirmed', false))
                        ->options(fn (): array => TaxCode::query()
                            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->pluck('name', 'code')
                            ->all())
                        ->required()
                        ->columnSpan(8),
                    RichEditor::make('description')
                        ->label(__('filament-accounting::fields.description'))
                        ->toolbarButtons([['bold', 'italic'], ['bulletList', 'orderedList']])
                        ->required()
                        ->columnSpanFull(),
                    Group::make([
                        Placeholder::make('tax_warning')
                            ->label(__('filament-accounting::tax_suggestions.warning_label'))
                            ->content(fn (Get $get): ?string => self::lineTaxWarning($get))
                            ->visible(fn (Get $get): bool => self::lineTaxWarning($get) !== null),
                        Toggle::make('tax_confirmed')
                            ->label(__('filament-accounting::tax_suggestions.confirm_review'))
                            ->visible(fn (Get $get): bool => self::lineTaxSuggestion($get)->requiresConfirmation ?? false)
                            ->accepted(fn (Get $get): bool => self::lineTaxSuggestion($get)->requiresConfirmation ?? false),
                    ])->columnSpanFull(),
                ])
                ->columns(12)
                ->defaultItems(1)
                ->columnSpanFull(),
            Textarea::make('correction_reason')
                ->label(__('filament-accounting::fields.correction_reason'))
                ->helperText(__('filament-accounting::fields.correction_help'))
                ->visible(fn (?Document $record): bool => $record?->document_status === DocumentStatus::Issued)
                ->required(fn (?Document $record): bool => $record?->document_status === DocumentStatus::Issued)
                ->maxLength(2000)->columnSpanFull(),
        ]);
    }

    private static function resetTaxConfirmations(Get $get, Set $set): void
    {
        foreach (array_keys($get('lines') ?? []) as $key) {
            $set("lines.{$key}.tax_confirmed", false);
        }
    }

    private static function updateDueDate(Get $get, Set $set): void
    {
        if (blank($get('issue_date'))) {
            return;
        }

        $days = Party::query()
            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
            ->where('is_customer', true)
            ->whereKey($get('party_id') ?? 0)
            ->value('payment_terms_days') ?? 7;
        $set('due_date', Carbon::parse($get('issue_date'))->addDays((int) $days)->toDateString());
    }

    private static function lineTotal(Get $get): string
    {
        try {
            $currency = (string) $get('../../currency');
            $price = ExactMoney::ofString(DecimalInput::normalize((string) $get('unit_price')), $currency);
            $net = LineMoneyCalculator::netMinor(DecimalInput::normalize((string) $get('quantity')), $price->minorAmount);
            $net = LineMoneyCalculator::netAfterDiscount($net, $get('discount'), $currency);

            return MoneyFormatter::format($net, $currency);
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function lineTaxSuggestion(Get $get): ?SalesTaxSuggestion
    {
        $entity = app(LegalEntityScope::class)->require();
        $item = CatalogItem::query()->where('legal_entity_id', $entity->getKey())
            ->whereKey($get('catalog_item_id') ?? 0)->first();
        $party = Party::query()->where('legal_entity_id', $entity->getKey())
            ->whereKey($get('../../party_id') ?? 0)->first();

        if (! $item instanceof CatalogItem || ! $party instanceof Party) {
            return null;
        }

        return app(SalesTaxSuggestionService::class)->suggest(
            $entity,
            $party,
            $item->type,
            (string) ($get('../../supply_date') ?: $get('../../issue_date') ?: now()->toDateString()),
            $item->default_tax_code,
        );
    }

    private static function lineTaxWarning(Get $get): ?string
    {
        $suggestion = self::lineTaxSuggestion($get);
        $selected = $get('tax_code');

        if ($suggestion === null || blank($selected)) {
            return null;
        }

        if ($selected !== $suggestion->taxCode) {
            return __('filament-accounting::tax_suggestions.conflicting_selection', [
                'selected' => $selected,
                'expected' => $suggestion->taxCode,
            ]).' '.$suggestion->explanation;
        }

        return $suggestion->requiresConfirmation ? $suggestion->explanation : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-accounting::fields.invoice_details'))
                ->schema([
                    TextEntry::make('number')->label(__('filament-accounting::fields.number')),
                    TextEntry::make('document_status')->label(__('filament-accounting::fields.document_status'))
                        ->state(fn (Document $record): string => self::displayStatus($record)->getLabel()),
                    TextEntry::make('party.legal_name')->label(__('filament-accounting::fields.customer')),
                    TextEntry::make('issue_date')->date()->label(__('filament-accounting::fields.issue_date')),
                    TextEntry::make('supply_date')->date()->label(__('filament-accounting::fields.supply_date')),
                    TextEntry::make('due_date')->date()->label(__('filament-accounting::fields.due_date')),
                    TextEntry::make('currency')->label(__('filament-accounting::fields.currency')),
                    TextEntry::make('correctedDocument.number')
                        ->label(__('filament-accounting::fields.corrected_invoice'))
                        ->url(fn (Document $record): ?string => $record->corrected_document_id
                            ? self::getUrl('view', ['record' => $record->correctedDocument]) : null),
                    TextEntry::make('correction.number')
                        ->label(__('filament-accounting::fields.replacement_invoice'))
                        ->placeholder(fn (Document $record): string => $record->correction ? DocumentStatus::Draft->getLabel() : '—')
                        ->url(fn (Document $record): ?string => $record->correction
                            ? self::getUrl('view', ['record' => $record->correction]) : null),
                    TextEntry::make('e_invoice_meta.correction_reason')->label(__('filament-accounting::fields.correction_reason')),
                    InvoiceInfolist::originalFiles(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            InvoiceInfolist::totals(),
            InvoiceInfolist::lines(),
        ]);
    }

    private static function totalsSection(): Section
    {
        return Section::make(__('filament-accounting::fields.totals'))
            ->schema([
                Placeholder::make('net_total')
                    ->label(__('filament-accounting::fields.net'))
                    ->content(fn (?Document $record): string => self::formatTotal($record, 'net_minor')),
                Placeholder::make('gross_total')
                    ->label(__('filament-accounting::fields.gross'))
                    ->content(fn (?Document $record): string => self::formatTotal($record, 'gross_minor')),
            ])
            ->columns(2)
            ->visible(fn (?Document $record): bool => $record instanceof Document)
            ->columnSpanFull();
    }

    private static function formatTotal(?Document $record, string $attribute): string
    {
        return $record instanceof Document
            ? MoneyFormatter::format((int) $record->getAttribute($attribute), $record->currency)
            : '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('filament-accounting::fields.number'))->searchable(),
                TextColumn::make('party.legal_name')->label(__('filament-accounting::fields.customer')),
                TextColumn::make('issue_date')->date()->label(__('filament-accounting::fields.issue_date')),
                TextColumn::make('document_status')->badge()->label(__('filament-accounting::fields.document_status'))
                    ->state(fn (Document $record): DocumentStatus => self::displayStatus($record))
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->getLabel())
                    ->color(fn (DocumentStatus $state): string => $state->getColor()),
                TextColumn::make('posting_status')->badge()->label(__('filament-accounting::fields.posting_status'))
                    ->formatStateUsing(fn (PostingStatus $state): string => $state->getLabel())
                    ->color(fn (PostingStatus $state): string => $state->getColor()),
                TextColumn::make('gross_minor')
                    ->label(__('filament-accounting::fields.gross'))
                    ->formatStateUsing(fn ($state, Document $record): string => MoneyFormatter::format((int) $state, $record->currency)),
                TextColumn::make('payment_status')
                    ->label(__('filament-accounting::fields.payment_status'))
                    ->badge()
                    ->state(fn (Document $record): PaymentStatus => $record->paymentStatus())
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->getLabel())
                    ->color(fn (PaymentStatus $state): string => $state->getColor()),
                TextColumn::make('settlements_count')
                    ->label(__('filament-accounting::fields.assigned_transactions')),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteDraftAction(),
                SalesInvoiceCompletionAction::make(),
                self::issueAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
            'create' => CreateSalesInvoice::route('/create'),
            'view' => ViewSalesInvoice::route('/{record}'),
            'edit' => EditSalesInvoice::route('/{record}/edit'),
        ];
    }

    public static function canEdit($record): bool
    {
        return $record instanceof Document
            && in_array($record->document_status, [DocumentStatus::Draft, DocumentStatus::Issued], true)
            && ! $record->correction()->exists()
            && (string) $record->legal_entity_id === (string) app(LegalEntityScope::class)->current()?->getKey()
            && app(AccountingAuthorizer::class)->can('create_draft_invoices', $record)
            && ($record->document_status === DocumentStatus::Draft || app(AccountingAuthorizer::class)->can('issue_invoices', $record));
    }

    private static function displayStatus(Document $record): DocumentStatus
    {
        return $record->correction?->posting_status === PostingStatus::Posted ? DocumentStatus::Corrected : $record->document_status;
    }

    public static function issueAction(): Action
    {
        return Action::make('issue')
            ->databaseTransaction(false)
            ->label(__('filament-accounting::actions.issue'))
            ->visible(fn (Document $record): bool => $record->document_status === DocumentStatus::Draft
                && app(AccountingAuthorizer::class)->can('issue_invoices', app(LegalEntityScope::class)->current())
                && app(AccountingAuthorizer::class)->can('post_documents', $record))
            ->action(function (Document $record, IssueSalesInvoice $issuer, Component $livewire): void {
                try {
                    $issuer->issue($record);
                    Notification::make()->title(__('filament-accounting::notifications.invoice_issued'))->success()->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title(__('filament-accounting::errors.invoice_completion_failed'))->send();
                }
                $livewire->redirect(self::getUrl('view', ['record' => $record]));
            });
    }

    public static function canDelete($record): bool
    {
        return $record instanceof Document && $record->document_status === DocumentStatus::Draft
            && $record->posting_status === PostingStatus::Unposted && self::canEdit($record);
    }

    public static function deleteDraftAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (Document $record): bool => self::canDelete($record))
            // The first confirmation modal must render together with the page's modal container.
            ->mountUsing(fn ($livewire) => $livewire->forceRender())
            ->successRedirectUrl(fn (): string => self::getUrl('index'))
            ->using(fn (Document $record): bool => app(IssueSalesInvoice::class)->deleteDraft($record));
    }

    public static function generateArtifactsAction(): Action
    {
        return Action::make('generateArtifacts')
            ->label(__('filament-accounting::actions.generate_invoice_artifacts'))
            ->icon('heroicon-o-arrow-path')
            ->databaseTransaction(false)
            ->visible(fn (Document $record): bool => $record->document_status === DocumentStatus::Issued
                && app(AccountingAuthorizer::class)->can('issue_invoices', app(LegalEntityScope::class)->current()))
            ->action(function (Document $record, Component $livewire): void {
                try {
                    app(GenerateInvoiceArtifacts::class)->handle($record);
                    Notification::make()->success()->title(__('filament-accounting::notifications.invoice_artifacts_ready'))->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title(__('filament-accounting::errors.invoice_completion_failed'))->send();
                }
                $livewire->redirect(self::getUrl('view', ['record' => $record]));
            });
    }
}
