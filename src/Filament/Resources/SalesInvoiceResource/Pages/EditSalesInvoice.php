<?php

namespace FilamentAccounting\Filament\Resources\SalesInvoiceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Support\ExactMoney;
use Illuminate\Database\Eloquent\Model;

class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return ! ($record instanceof Document && $record->isIssuedOrReceived());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Document $record */
        $record = $this->getRecord();
        $data['lines'] = $record->lines->map(fn ($line): array => [
            'catalog_item_id' => $line->catalog_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_price' => ExactMoney::ofMinor((int) $line->unit_price_minor, (string) $record->currency)->decimalString(),
            'tax_code' => $line->tax_code,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Document $record */
        return app(IssueSalesInvoice::class)->updateDraft($record, $data);
    }
}
