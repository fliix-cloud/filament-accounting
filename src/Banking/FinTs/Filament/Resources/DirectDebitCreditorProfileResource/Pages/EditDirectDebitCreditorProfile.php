<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use Illuminate\Validation\ValidationException;

class EditDirectDebitCreditorProfile extends EditRecord
{
    protected static string $resource = DirectDebitCreditorProfileResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! SepaIdentifier::isValidCreditorIdentifier((string) ($data['creditor_identifier'] ?? ''))) {
            throw ValidationException::withMessages([
                'creditor_identifier' => __('filament-accounting::banking/fints/validation.creditor_identifier'),
            ]);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
