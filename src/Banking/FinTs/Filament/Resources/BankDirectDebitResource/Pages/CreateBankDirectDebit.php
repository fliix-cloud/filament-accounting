<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateType;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitSequenceType;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithScaChallenge;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\DirectDebitService;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Banking\FinTs\Support\Money;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Validation\ValidationException;

class CreateBankDirectDebit extends CreateRecord
{
    use InteractsWithScaChallenge;

    protected static string $resource = BankDirectDebitResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $owner = app(OwnerScope::class)->require();
        $account = BankAccount::query()
            ->whereKey($data['accounting_bank_account_id'] ?? null)
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections($owner)->select('id'))
            ->usable()
            ->firstOrFail();
        $profile = DirectDebitCreditorProfile::query()
            ->where('legal_entity_id', $owner->getKey())
            ->whereKey($data['creditor_profile_id'] ?? null)
            ->first();
        $mandate = DirectDebitMandate::query()
            ->where('legal_entity_id', $owner->getKey())
            ->whereKey($data['direct_debit_mandate_id'] ?? null)
            ->where('creditor_profile_id', $profile?->id)
            ->first();

        if (! $profile instanceof DirectDebitCreditorProfile) {
            throw ValidationException::withMessages(['creditor_profile_id' => __('filament-accounting::banking/fints/validation.creditor_profile')]);
        }
        if (! $mandate instanceof DirectDebitMandate || ! $mandate->canCollect()) {
            throw ValidationException::withMessages(['direct_debit_mandate_id' => __('filament-accounting::banking/fints/validation.mandate_active')]);
        }

        $sequence = DirectDebitSequenceType::tryFrom((string) ($data['sequence_type'] ?? ''));
        $expected = $mandate->nextSequenceType();
        $validFinal = $mandate->mandate_type === DirectDebitMandateType::Recurring
            && $mandate->first_used_at !== null
            && $sequence === DirectDebitSequenceType::Final;
        if ($sequence !== $expected && ! $validFinal) {
            throw ValidationException::withMessages(['sequence_type' => __('filament-accounting::banking/fints/validation.sequence_type')]);
        }

        if ($mandate->scheme === DirectDebitScheme::B2b && $mandate->debtor_bank_confirmed_at === null) {
            throw ValidationException::withMessages(['direct_debit_mandate_id' => __('filament-accounting::banking/fints/validation.b2b_bank_confirmation')]);
        }

        if (empty($data['requested_collection_date'])) {
            throw ValidationException::withMessages(['requested_collection_date' => __('filament-accounting::banking/fints/validation.collection_date')]);
        }

        $data = array_merge($data, [
            'bank_connection_id' => $account->bank_connection_id,
            'creditor_profile_id' => $profile->id,
            'direct_debit_mandate_id' => $mandate->id,
            'creditor_name' => $profile->name,
            'creditor_identifier' => $profile->creditor_identifier,
            'creditor_street' => $profile->street,
            'creditor_building_number' => $profile->building_number,
            'creditor_postal_code' => $profile->postal_code,
            'creditor_city' => $profile->city,
            'creditor_country' => $profile->country,
            'debtor_name' => $mandate->debtor_name,
            'debtor_iban' => $mandate->debtor_iban,
            'debtor_bic' => $mandate->debtor_bic,
            'debtor_street' => $mandate->debtor_street,
            'debtor_building_number' => $mandate->debtor_building_number,
            'debtor_postal_code' => $mandate->debtor_postal_code,
            'debtor_city' => $mandate->debtor_city,
            'debtor_country' => $mandate->debtor_country,
            'mandate_id' => $mandate->reference,
            'mandate_signed_on' => $mandate->signed_on,
            'scheme' => $mandate->scheme,
            'currency' => 'EUR',
            'amount' => Money::normalize($data['amount'] ?? '0'),
            'status' => PaymentStatus::Draft,
        ]);

        $actor = app(BankActorResolver::class)->resolve();
        if ($actor) {
            $data['initiated_by_type'] = $actor->getMorphClass();
            $data['initiated_by_id'] = (string) $actor->getKey();
        }

        $candidate = new BankDirectDebit;
        $candidate->forceFill($data);
        app(BankAuthorizer::class)->authorize('create_bank_direct_debit', $candidate);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        if (! $record instanceof BankDirectDebit) {
            return;
        }

        $outcome = FintsUi::run(fn (): ScaOutcome => app(DirectDebitService::class)->submit(
            $record,
            app(BankActorResolver::class)->resolve(),
            BankDirectDebitResource::getUrl('view', ['record' => $record]),
        ));

        if ($this->openSca($outcome)) {
            return;
        }

        Notification::make()->title(__('filament-accounting::banking/fints/notifications.direct_debit_submitted'))->success()->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->scaPageUrl() ?? parent::getRedirectUrl();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return filled($this->scaSessionUuid) ? null : parent::getCreatedNotificationTitle();
    }
}
