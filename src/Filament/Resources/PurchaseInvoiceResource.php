<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ListPurchaseInvoices;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;

class PurchaseInvoiceResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'accounting/purchase-invoices';

    protected static ?int $navigationSort = 11;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', DocumentType::PurchaseInvoice)
            ->with(['party', 'openItem.settlements']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label(__('filament-accounting::fields.supplier'))
                ->options(fn (): array => Party::query()->where('is_supplier', true)->orderBy('legal_name')->pluck('legal_name', 'id')->all())
                ->required(),
            TextInput::make('supplier_invoice_number')->label(__('filament-accounting::fields.supplier_invoice_number')),
            DatePicker::make('issue_date')->label(__('filament-accounting::fields.issue_date'))->required(),
            DatePicker::make('receipt_date')->label(__('filament-accounting::fields.receipt_date')),
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->maxLength(3)->required(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    TextInput::make('description')->label(__('filament-accounting::fields.description'))->required(),
                    TextInput::make('quantity')->label(__('filament-accounting::fields.quantity'))->required(),
                    TextInput::make('unit_price_minor')->label(__('filament-accounting::fields.unit_price'))->numeric()->required(),
                    TextInput::make('tax_code')->label(__('filament-accounting::fields.tax_code')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label(__('filament-accounting::fields.number'))->searchable(),
            TextColumn::make('supplier_invoice_number')->label(__('filament-accounting::fields.supplier_invoice_number')),
            TextColumn::make('party.legal_name')->label(__('filament-accounting::fields.supplier')),
            TextColumn::make('document_status')->badge()->label(__('filament-accounting::fields.document_status')),
            TextColumn::make('posting_status')->badge()->label(__('filament-accounting::fields.posting_status')),
            TextColumn::make('gross_minor')
                ->label(__('filament-accounting::fields.gross'))
                ->formatStateUsing(fn ($state, Document $record): string => MoneyFormatter::format((int) $state, $record->currency)),
            TextColumn::make('payment_status')
                ->label(__('filament-accounting::fields.payment_status'))
                ->state(fn (Document $record): string => __('filament-accounting::statuses.payment.'.$record->paymentStatus()->value)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseInvoices::route('/'),
            'create' => CreatePurchaseInvoice::route('/create'),
            'view' => ViewPurchaseInvoice::route('/{record}'),
        ];
    }
}
