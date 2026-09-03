<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitCreditorProfileResource;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use Illuminate\Validation\ValidationException;

class CreateDirectDebitCreditorProfile extends CreateRecord
{
    protected static string $resource = DirectDebitCreditorProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! SepaIdentifier::isValidCreditorIdentifier((string) ($data['creditor_identifier'] ?? ''))) {
            throw ValidationException::withMessages([
                'creditor_identifier' => __('filament-accounting::banking/fints/validation.creditor_identifier'),
            ]);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): DirectDebitCreditorProfile
    {
        $record = new DirectDebitCreditorProfile;
        $record->fill($data);
        $record->legal_entity_id = app(OwnerScope::class)->require()->getKey();
        $record->save();

        return $record;
    }
}
