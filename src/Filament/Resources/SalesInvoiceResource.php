<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\CreateSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\EditSalesInvoice;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ListSalesInvoices;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages\ViewSalesInvoice;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;

class SalesInvoiceResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = Document::class;

    protected static ?string $slug = 'accounting/sales-invoices';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::SALES;
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
        return parent::getEloquentQuery()
            ->where('type', DocumentType::SalesInvoice)
            ->with(['party', 'openItem.settlements', 'settlements.reconciliation.statementLine'])
            ->withCount('settlements');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label(__('filament-accounting::fields.customer'))
                ->options(fn (): array => Party::query()->where('is_customer', true)->orderBy('legal_name')->pluck('legal_name', 'id')->all())
                ->required()
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
            DatePicker::make('issue_date')->label(__('filament-accounting::fields.issue_date'))->required(),
            DatePicker::make('due_date')->label(__('filament-accounting::fields.due_date')),
            TextInput::make('currency')->label(__('filament-accounting::fields.currency'))->maxLength(3)->required(),
            Repeater::make('lines')
                ->label(__('filament-accounting::fields.lines'))
                ->relationship()
                ->schema([
                    TextInput::make('description')->label(__('filament-accounting::fields.description'))->required(),
                    TextInput::make('quantity')->label(__('filament-accounting::fields.quantity'))->required(),
                    TextInput::make('unit_price_minor')->label(__('filament-accounting::fields.unit_price'))->numeric()->required(),
                    TextInput::make('tax_code')->label(__('filament-accounting::fields.tax_code')),
                ])
                ->disabled(fn (?Document $record): bool => $record?->isIssuedOrReceived() ?? false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('filament-accounting::fields.number'))->searchable(),
                TextColumn::make('party.legal_name')->label(__('filament-accounting::fields.customer')),
                TextColumn::make('issue_date')->date()->label(__('filament-accounting::fields.issue_date')),
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
            ])
            ->recordActions([
                Action::make('issue')
                    ->label(__('filament-accounting::actions.issue'))
                    ->visible(fn (Document $record): bool => $record->document_status->value === 'draft')
                    ->action(function (Document $record, IssueSalesInvoice $issuer): void {
                        $entity = app(LegalEntityScope::class)->require();
                        $issuer->handle($entity, [
                            'party_id' => $record->party_id,
                            'issue_date' => $record->issue_date?->toDateString(),
                            'due_date' => $record->due_date?->toDateString(),
                            'currency' => $record->currency,
                            'lines' => $record->lines->map(fn ($line): array => $line->only([
                                'description', 'quantity', 'unit_price_minor', 'tax_code',
                            ]))->all(),
                        ]);
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
