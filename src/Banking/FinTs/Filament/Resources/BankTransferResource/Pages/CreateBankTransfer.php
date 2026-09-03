<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Filament\Concerns\InteractsWithScaChallenge;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\TransferService;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Banking\FinTs\Support\Iban;
use FilamentAccounting\Banking\FinTs\Support\Money;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Validation\ValidationException;

class CreateBankTransfer extends CreateRecord
{
    use InteractsWithScaChallenge;

    protected static string $resource = BankTransferResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $account = BankAccount::query()
            ->whereKey($data['accounting_bank_account_id'])
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->usable()
            ->firstOrFail();

        if (! Iban::isValid((string) ($data['recipient_iban'] ?? ''))) {
            throw ValidationException::withMessages([
                'recipient_iban' => __('filament-accounting::banking/fints/validation.iban'),
            ]);
        }

        $data['bank_connection_id'] = $account->bank_connection_id;
        $data['amount'] = Money::normalize($data['amount'] ?? '0');
        $data['status'] = PaymentStatus::Draft;
        $actor = app(BankActorResolver::class)->resolve();
        if ($actor) {
            $data['initiated_by_type'] = $actor->getMorphClass();
            $data['initiated_by_id'] = (string) $actor->getKey();
        }

        // Authorize the fully populated, unsaved record. A denied operation must
        // not leave behind a payment draft that the actor was never allowed to create.
        $candidate = new BankTransfer;
        $candidate->forceFill($data);
        app(BankAuthorizer::class)->authorize('create_bank_transfer', $candidate);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        if (! $record instanceof BankTransfer) {
            return;
        }

        $account = BankAccount::query()
            ->whereKey($record->accounting_bank_account_id)
            ->where('bank_connection_id', $record->bank_connection_id)
            ->usable()
            ->firstOrFail();
        $record->accounting_bank_account_id = $account->id;
        $record->save();

        $outcome = FintsUi::run(fn (): ScaOutcome => app(TransferService::class)->submit(
            $record,
            app(BankActorResolver::class)->resolve(),
            BankTransferResource::getUrl('view', ['record' => $record]),
        ));

        if ($this->openSca($outcome)) {
            return;
        }

        Notification::make()->title(__('filament-accounting::banking/fints/notifications.transfer_submitted'))->success()->send();
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
