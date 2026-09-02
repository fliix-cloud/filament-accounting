<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseInvoice extends EditRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Document $record */
        $record = $this->getRecord();
        $data['lines'] = $record->lines->map(fn (DocumentLine $line): array => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_price' => ExactMoney::ofMinor($line->unit_price_minor, $record->currency)->decimalString(),
            'classification_code' => $line->classification_code,
            'ledger_account_id' => $line->ledger_account_id,
            'tax_code' => $line->tax_code,
            'classification_confirmed' => $line->classification_confirmed,
            'tax_confirmed' => $line->tax_confirmed,
            'imported_tax_code' => $line->imported_tax_code,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Document) {
            throw new \LogicException('Purchase invoice resource returned an unexpected record type.');
        }

        return app(RegisterPurchaseInvoice::class)->updateDraft($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receiveAndPost')
                ->label(__('filament-accounting::actions.receive_and_post'))
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->action(function (): void {
                    $record = $this->getRecord();
                    if (! $record instanceof Document) {
                        throw new \LogicException('Purchase invoice resource returned an unexpected record type.');
                    }

                    $document = app(RegisterPurchaseInvoice::class)->receive($record);
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $document]));
                }),
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return ! $record instanceof Document || $record->document_status === DocumentStatus::Draft;
    }
}
