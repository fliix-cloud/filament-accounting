<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use FilamentAccounting\Services\PurchaseInvoiceIntakeStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListPurchaseInvoiceIntakes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseInvoiceResource::class;

    protected string $view = 'filament-accounting::filament.pages.purchase-invoice-intakes';

    public function mount(): void
    {
        app(AccountingAuthorizer::class)->authorize('register_purchase_invoices', app(LegalEntityScope::class)->require());
    }

    public function getTitle(): string
    {
        return __('filament-accounting::fields.open_intakes');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('upload')->label(__('filament-accounting::fields.upload_original_invoice'))
            ->url(PurchaseInvoiceResource::getUrl('create'))];
    }

    public function table(Table $table): Table
    {
        $entity = app(LegalEntityScope::class)->require();
        app(AccountingAuthorizer::class)->authorize('register_purchase_invoices', $entity);

        return $table->query(PurchaseInvoiceIntake::query()->where('legal_entity_id', $entity->getKey())->where('status', '!=', 'complete'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('filename')->label(__('filament-accounting::fields.original_invoice'))
                    ->state(fn (PurchaseInvoiceIntake $record): string => $record->files['primary']['filename']),
                TextColumn::make('created_at')->label(__('filament-accounting::fields.intake_received_at'))->dateTime(),
                TextColumn::make('status')->label(__('filament-accounting::fields.document_status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('filament-accounting::fields.intake_status_'.$state)),
                TextColumn::make('last_error')->label(__('filament-accounting::fields.intake_details'))->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->recordActions([
                $this->downloadAction('primary', 'intake_original'),
                $this->downloadAction('companion', 'intake_companion'),
                Action::make('retry')->label(__('filament-accounting::fields.intake_retry'))
                    ->databaseTransaction(false)
                    ->visible(fn (PurchaseInvoiceIntake $record): bool => in_array($record->status, ['ready', 'failed'], true)
                        && $record->preserved_at !== null)
                    ->action(function (PurchaseInvoiceIntake $record): void {
                        try {
                            $result = app(ImportPurchaseInvoice::class)->resume($record);
                            $this->redirect(PurchaseInvoiceResource::getUrl('edit', ['record' => $result->document]));
                        } catch (\Throwable $exception) {
                            report($exception);
                            Notification::make()->danger()->title(__('filament-accounting::errors.intake_processing_failed'))->send();
                        }
                    }),
            ]);
    }

    private function downloadAction(string $role, string $label): Action
    {
        return Action::make('download_'.$role)->label(__('filament-accounting::fields.'.$label))
            ->visible(fn (PurchaseInvoiceIntake $record): bool => $record->preserved_at !== null
                && $record->status !== 'integrity_failed' && isset($record->files[$role]))
            ->action(function (PurchaseInvoiceIntake $record) use ($role): StreamedResponse {
                $entity = app(LegalEntityScope::class)->require();
                $contents = app(PurchaseInvoiceIntakeStore::class)->read($entity, $record)[$role];

                return response()->streamDownload(static function () use ($contents): void {
                    echo $contents;
                }, $record->files[$role]['filename'], [
                    'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
                ]);
            });
    }
}
