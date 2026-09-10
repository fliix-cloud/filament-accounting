<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\InvoicePaymentMethod;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Filament\Support\SalesInvoicePreviewAction;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [SalesInvoicePreviewAction::make(), SalesInvoiceResource::deleteDraftAction()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Document $record */
        $record = $this->getRecord();
        $data['payment_method'] = ($record->payment_method ?? InvoicePaymentMethod::CreditTransfer)->value;
        $data['lines'] = $record->lines->map(fn ($line): array => [
            'catalog_item_id' => $line->catalog_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_price' => ExactMoney::ofMinor((int) $line->unit_price_minor, (string) $record->currency)->decimalString(),
            'tax_code' => $line->tax_code,
            'discount' => $line->discount,
            'account_role' => $line->account_role,
            'ledger_account_id' => $line->ledger_account_id,
            'service_from' => $line->service_from?->toDateString(),
            'service_to' => $line->service_to?->toDateString(),
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Document $record */
        try {
            if ($record->document_status === DocumentStatus::Issued) {
                $this->record = app(IssueSalesInvoice::class)->correct($record, $data, (string) ($data['correction_reason'] ?? ''));

                return $this->record;
            }

            return app(IssueSalesInvoice::class)->updateDraft($record, $data);
        } catch (DocumentException $exception) {
            throw ValidationException::withMessages(['data.correction_reason' => $exception->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SalesInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
