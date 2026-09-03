<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Support\Iban;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use Illuminate\Validation\ValidationException;

class EditDirectDebitMandate extends EditRecord
{
    protected static string $resource = DirectDebitMandateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;
        if (! $record instanceof DirectDebitMandate) {
            return $data;
        }

        if ($record->first_used_at !== null) {
            foreach (['creditor_profile_id', 'reference', 'scheme', 'mandate_type', 'debtor_iban', 'signed_on'] as $field) {
                $data[$field] = $record->getAttribute($field);
            }
        }

        if (! SepaIdentifier::isValidMandateReference((string) ($data['reference'] ?? ''))) {
            throw ValidationException::withMessages(['reference' => __('filament-accounting::banking/fints/validation.mandate_reference')]);
        }

        if (! Iban::isValid((string) ($data['debtor_iban'] ?? ''))) {
            throw ValidationException::withMessages(['debtor_iban' => __('filament-accounting::banking/fints/validation.iban')]);
        }

        if (! Iban::isValidBic($data['debtor_bic'] ?? null)) {
            throw ValidationException::withMessages(['debtor_bic' => __('filament-accounting::banking/fints/validation.bic')]);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(function (): bool {
                    $record = $this->record;

                    return $record instanceof DirectDebitMandate && $record->first_used_at === null;
                }),
        ];
    }
}
