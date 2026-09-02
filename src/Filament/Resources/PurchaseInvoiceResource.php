<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\EditPurchaseInvoice;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ListPurchaseInvoices;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;

class PurchaseInvoiceResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'accounting/purchase-invoices';

    protected static ?int $navigationSort = 11;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::PURCHASES;
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

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery())
            ->where('type', DocumentType::PurchaseInvoice)
            ->with(['party', 'openItem.settlements', 'settlements.reconciliation.statementLine'])
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
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->maxLength(3)->required(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->schema([
                    TextInput::make('description')->label(__('filament-accounting::fields.description'))->required(),
                    TextInput::make('quantity')->label(__('filament-accounting::fields.quantity'))->required(),
                    TextInput::make('unit_price')->label(__('filament-accounting::fields.unit_price'))->required(),
                    Select::make('classification_code')
                        ->label(__('filament-accounting::fields.expense_category'))
                        ->options([
                            'goods' => __('filament-accounting::fields.expense_categories.goods'),
                            'external_services' => __('filament-accounting::fields.expense_categories.external_services'),
                            'other_operating_expense' => __('filament-accounting::fields.expense_categories.other_operating_expense'),
                            'travel' => __('filament-accounting::fields.expense_categories.travel'),
                        ])
                        ->required(),
                    Select::make('ledger_account_id')
                        ->label(__('filament-accounting::fields.expense_account'))
                        ->options(function (): array {
                            $entity = app(LegalEntityScope::class)->require();

                            return LedgerAccount::query()
                                ->where('legal_entity_id', $entity->getKey())
                                ->where('is_active', true)
                                ->whereIn('type', [AccountType::Expense->value, AccountType::Asset->value])
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (LedgerAccount $account): array => [$account->getKey() => $account->label()])
                                ->all();
                        })
                        ->searchable()
                        ->required(),
                    Select::make('tax_code')
                        ->label(__('filament-accounting::fields.tax_treatment'))
                        ->options(function (): array {
                            $entity = app(LegalEntityScope::class)->require();

                            return TaxCode::query()->where('legal_entity_id', $entity->getKey())->where('is_active', true)->orderBy('code')->pluck('name', 'code')->all();
                        })
                        ->required(),
                    Toggle::make('classification_confirmed')
                        ->label(__('filament-accounting::fields.confirm_expense_category')),
                    Toggle::make('tax_confirmed')
                        ->label(__('filament-accounting::fields.confirm_tax_treatment')),
                    TextInput::make('imported_tax_code')->hidden(),
                ])
                ->defaultItems(1),
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
            TextColumn::make('settlements_count')
                ->label(__('filament-accounting::fields.assigned_transactions')),
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
