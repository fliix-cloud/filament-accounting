<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Banking\FinTs\Filament\Resources\DirectDebitMandateResource;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use FilamentAccounting\Models\PartyBankAccount;
use Illuminate\Validation\ValidationException;

class CreateDirectDebitMandate extends CreateRecord
{
    protected static string $resource = DirectDebitMandateResource::class;

    public function mount(): void
    {
        parent::mount();

        $account = PartyBankAccount::query()
            ->where('legal_entity_id', app(OwnerScope::class)->require()->getKey())
            ->whereKey(request()->integer('party_bank_account_id'))
            ->when(request()->integer('party') > 0, fn ($query) => $query->where('party_id', request()->integer('party')))
            ->first();

        if ($account instanceof PartyBankAccount) {
            $this->form->fill([
                ...$this->form->getRawState(),
                'party_bank_account_id' => $account->getKey(),
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $owner = app(OwnerScope::class)->require();
        $profile = DirectDebitCreditorProfile::query()
            ->where('legal_entity_id', $owner->getKey())
            ->whereKey($data['creditor_profile_id'] ?? null)
            ->first();
        $bankAccount = PartyBankAccount::query()
            ->where('legal_entity_id', $owner->getKey())
            ->whereKey($data['party_bank_account_id'] ?? null)
            ->first();

        if (! $profile instanceof DirectDebitCreditorProfile) {
            throw ValidationException::withMessages(['creditor_profile_id' => __('filament-accounting::banking/fints/validation.creditor_profile')]);
        }

        if (! $bankAccount instanceof PartyBankAccount) {
            throw ValidationException::withMessages(['party_bank_account_id' => __('filament-accounting::banking/fints/validation.iban')]);
        }

        if (! SepaIdentifier::isValidMandateReference((string) ($data['reference'] ?? ''))) {
            throw ValidationException::withMessages(['reference' => __('filament-accounting::banking/fints/validation.mandate_reference')]);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): DirectDebitMandate
    {
        $record = new DirectDebitMandate;
        $record->fill($data);
        $record->legal_entity_id = app(OwnerScope::class)->require()->getKey();
        $record->save();

        return $record;
    }
}
