<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\EditPurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ListPurchaseInvoices;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use FilamentAccounting\Filament\Support\DocumentAttachmentActions;
use FilamentAccounting\Filament\Support\InvoiceInfolist;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\DeletePurchaseInvoiceDraft;
use FilamentAccounting\Support\MoneyFormatter;
use FilamentAccounting\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;

class PurchaseInvoiceResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'accounting/purchase-invoices';

    protected static ?int $navigationSort = 11;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.purchase_invoices');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.purchase_invoice.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.purchase_invoice.plural');
    }

    protected static function ability(): string
    {
        return 'register_purchase_invoices';
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDiscard(Document $record): bool
    {
        return $record->type === DocumentType::PurchaseInvoice
            && $record->document_status === DocumentStatus::Draft
            && $record->posting_status === PostingStatus::Unposted
            && (string) $record->legal_entity_id === (string) app(LegalEntityScope::class)->current()?->getKey()
            && app(AccountingAuthorizer::class)->can('discard_purchase_invoices', $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery())
            ->where('type', DocumentType::PurchaseInvoice)
            ->with(['party', 'lines.document', 'attachments', 'openItem.settlements', 'settlements.reconciliation.statementLine'])
            ->withCount('settlements');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label(__('filament-accounting::fields.supplier'))
                ->options(function (): array {
                    $entity = app(LegalEntityScope::class)->require();

                    return Party::query()->where('legal_entity_id', $entity->getKey())->where('is_supplier', true)->where('is_active', true)->orderBy('legal_name')->pluck('legal_name', 'id')->all();
                })
                ->searchable()
                ->required(),
            TextInput::make('supplier_invoice_number')->label(__('filament-accounting::fields.supplier_invoice_number')),
            DatePicker::make('issue_date')->label(__('filament-accounting::fields.issue_date'))->required(),
            DatePicker::make('receipt_date')->label(__('filament-accounting::fields.receipt_date')),
            DatePicker::make('supply_date')->label(__('filament-accounting::fields.supply_date')),
            Select::make('currency')->label(__('filament-accounting::fields.currency'))->options(ReferenceData::currencies())->searchable()->required(),
            self::totalsSection(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    TextInput::make('description')
                        ->label(__('filament-accounting::fields.description'))
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('quantity')
                        ->label(__('filament-accounting::fields.quantity'))
                        ->required()
                        ->columnSpan(2),
                    Select::make('unit')
                        ->label(__('filament-accounting::fields.unit'))
                        ->options(fn (Get $get): array => ReferenceData::catalogUnits($get('unit')))
                        ->searchable()
                        ->columnSpan(2),
                    TextInput::make('unit_price')
                        ->label(__('filament-accounting::fields.unit_price'))
                        ->required()
                        ->columnSpan(2),
                    Select::make('classification_code')
                        ->label(__('filament-accounting::fields.expense_category'))
                        ->options([
                            'goods' => __('filament-accounting::fields.expense_categories.goods'),
                            'external_services' => __('filament-accounting::fields.expense_categories.external_services'),
                            'other_operating_expense' => __('filament-accounting::fields.expense_categories.other_operating_expense'),
                            'office_supplies' => __('filament-accounting::fields.expense_categories.office_supplies'),
                            'software_it' => __('filament-accounting::fields.expense_categories.software_it'),
                            'rent_utilities' => __('filament-accounting::fields.expense_categories.rent_utilities'),
                            'telecom' => __('filament-accounting::fields.expense_categories.telecom'),
                            'travel' => __('filament-accounting::fields.expense_categories.travel'),
                            'insurance' => __('filament-accounting::fields.expense_categories.insurance'),
                            'bank_fees' => __('filament-accounting::fields.expense_categories.bank_fees'),
                            'personnel' => __('filament-accounting::fields.expense_categories.personnel'),
                            'suspense' => __('filament-accounting::fields.expense_categories.suspense'),
                        ])
                        ->required()
                        ->columnSpan(3),
                    Select::make('tax_code')
                        ->label(__('filament-accounting::fields.tax_treatment'))
                        ->options(function (): array {
                            $entity = app(LegalEntityScope::class)->require();

                            return TaxCode::query()->where('legal_entity_id', $entity->getKey())->where('is_active', true)->orderBy('code')->pluck('name', 'code')->all();
                        })
                        ->required()
                        ->columnSpan(3),
                    TextInput::make('imported_tax_code')->hidden(),
                ])
                ->columns(12)
                ->defaultItems(1)
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-accounting::fields.invoice_details'))
                ->schema([
                    TextEntry::make('party.legal_name')->label(__('filament-accounting::fields.supplier')),
                    TextEntry::make('supplier_invoice_number')->label(__('filament-accounting::fields.supplier_invoice_number')),
                    TextEntry::make('issue_date')->date()->label(__('filament-accounting::fields.issue_date')),
                    TextEntry::make('receipt_date')->date()->label(__('filament-accounting::fields.receipt_date')),
                    TextEntry::make('supply_date')->date()->label(__('filament-accounting::fields.supply_date')),
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
        return $table->columns([
            TextColumn::make('number')->label(__('filament-accounting::fields.number'))->searchable(),
            TextColumn::make('supplier_invoice_number')->label(__('filament-accounting::fields.supplier_invoice_number')),
            TextColumn::make('party.legal_name')->label(__('filament-accounting::fields.supplier')),
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
        ])->recordActions([
            ...DocumentAttachmentActions::table(),
            Action::make('discard')
                ->label(__('filament-accounting::actions.discard_draft'))
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('filament-accounting::actions.discard_draft_description'))
                ->schema([
                    Textarea::make('reason')->label(__('filament-accounting::fields.reason'))->required()->maxLength(2000),
                ])
                ->visible(fn (Document $record): bool => static::canDiscard($record))
                ->action(fn (Document $record, array $data): bool => app(DeletePurchaseInvoiceDraft::class)->handle($record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseInvoices::route('/'),
            'create' => CreatePurchaseInvoice::route('/create'),
            'edit' => EditPurchaseInvoice::route('/{record}/edit'),
            'view' => ViewPurchaseInvoice::route('/{record}'),
        ];
    }
}
