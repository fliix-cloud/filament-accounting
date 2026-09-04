<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\MoneyFormatter;
use FilamentAccounting\Support\ReferenceData;
use FilamentAccounting\Support\RichText;
use FilamentAccounting\Tax\SalesTaxSuggestionService;
use Illuminate\Database\Eloquent\Builder;

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
            ->with(['party', 'lines.document', 'attachments', 'openItem.settlements', 'settlements.reconciliation.statementLine'])
            ->withCount('settlements');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label(__('filament-accounting::fields.customer'))
                ->options(fn (): array => Party::query()
                    ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                    ->where('is_customer', true)
                    ->where('is_active', true)
                    ->orderBy('legal_name')
                    ->pluck('legal_name', 'id')
                    ->all())
                ->required()
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            DatePicker::make('issue_date')->label(__('filament-accounting::fields.issue_date'))->required()
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            DatePicker::make('supply_date')->label(__('filament-accounting::fields.supply_date'))
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            DatePicker::make('due_date')->label(__('filament-accounting::fields.due_date'))
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            Select::make('currency')->label(__('filament-accounting::fields.currency'))->options(ReferenceData::currencies())->searchable()->required()
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            self::totalsSection(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    Select::make('catalog_item_id')
                        ->label(__('filament-accounting::fields.catalog_item'))
                        ->options(fn (): array => CatalogItem::query()
                            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            $item = CatalogItem::query()
                                ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                                ->whereKey($state)
                                ->first();

                            if (! $item instanceof CatalogItem) {
                                return;
                            }

                            $set('description', RichText::catalogLine($item->name, $item->description));
                            $set('quantity', $item->default_quantity);
                            $set('unit', $item->unit);
                            $set('unit_price', ExactMoney::ofMinor((int) $item->default_unit_price_minor, (string) $item->currency)->decimalString());

                            $party = Party::query()
                                ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                                ->whereKey($get('../../party_id') ?? 0)
                                ->first();
                            if (! $party instanceof Party) {
                                $set('tax_code', $item->default_tax_code);

                                return;
                            }

                            $date = (string) ($get('../../supply_date') ?: $get('../../issue_date') ?: now()->toDateString());
                            $suggestion = app(SalesTaxSuggestionService::class)->suggest(
                                app(LegalEntityScope::class)->require(),
                                $party,
                                $item->type,
                                $date,
                                $item->default_tax_code,
                            );
                            $set('tax_code', $suggestion->taxCode);
                            $set('tax_suggestion_explanation', $suggestion->explanation);
                            $set('tax_requires_confirmation', $suggestion->requiresConfirmation);
                            $set('tax_confirmed', ! $suggestion->requiresConfirmation);
                        })
                        ->columnSpan(3),
                    RichEditor::make('description')
                        ->label(__('filament-accounting::fields.description'))
                        ->toolbarButtons([['bold', 'italic'], ['bulletList', 'orderedList']])
                        ->required()
                        ->columnSpan(9),
                    TextInput::make('quantity')->label(__('filament-accounting::fields.quantity'))->required()->columnSpan(2),
                    Select::make('unit')
                        ->label(__('filament-accounting::fields.unit'))
                        ->options(fn (Get $get): array => ReferenceData::catalogUnits($get('unit')))
                        ->searchable()
                        ->columnSpan(2),
                    TextInput::make('unit_price')->label(__('filament-accounting::fields.unit_price'))->numeric()->required()->columnSpan(2),
                    Select::make('tax_code')
                        ->label(__('filament-accounting::fields.tax_treatment'))
                        ->options(fn (): array => TaxCode::query()
                            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->pluck('name', 'code')
                            ->all())
                        ->required()
                        ->columnSpan(3),
                    Hidden::make('tax_suggestion_explanation'),
                    Hidden::make('tax_requires_confirmation'),
                    Group::make([
                        Placeholder::make('tax_suggestion')
                            ->label(__('filament-accounting::fields.tax_suggestion'))
                            ->content(fn (Get $get): string => (string) ($get('tax_suggestion_explanation') ?: __('filament-accounting::fields.tax_suggestion_help'))),
                        Toggle::make('tax_confirmed')
                            ->label(__('filament-accounting::fields.confirm_tax_suggestion'))
                            ->visible(fn (Get $get): bool => (bool) $get('tax_requires_confirmation'))
                            ->accepted(fn (Get $get): bool => (bool) $get('tax_requires_confirmation')),
                    ])->columnSpan(3),
                ])
                ->columns(12)
                ->defaultItems(1)
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false)
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-accounting::fields.invoice_details'))
                ->schema([
                    TextEntry::make('number')->label(__('filament-accounting::fields.number')),
                    TextEntry::make('party.legal_name')->label(__('filament-accounting::fields.customer')),
                    TextEntry::make('issue_date')->date()->label(__('filament-accounting::fields.issue_date')),
                    TextEntry::make('supply_date')->date()->label(__('filament-accounting::fields.supply_date')),
                    TextEntry::make('due_date')->date()->label(__('filament-accounting::fields.due_date')),
                    TextEntry::make('currency')->label(__('filament-accounting::fields.currency')),
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
                Action::make('issue')
                    ->label(__('filament-accounting::actions.issue'))
                    ->visible(fn (Document $record): bool => $record->document_status->value === 'draft')
                    ->action(function (Document $record, IssueSalesInvoice $issuer): void {
                        $issuer->issue($record);
                        Notification::make()->title(__('filament-accounting::notifications.invoice_issued'))->success()->send();
                    }),
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
}
