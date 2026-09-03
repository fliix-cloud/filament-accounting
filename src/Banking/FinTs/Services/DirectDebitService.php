<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\SendSEPADirectDebit;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Events\BankDirectDebitFailed;
use FilamentAccounting\Banking\FinTs\Exceptions\AmbiguousSubmissionException;
use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use FilamentAccounting\Banking\FinTs\Support\Iban;
use FilamentAccounting\Banking\FinTs\Support\Money;
use FilamentAccounting\Banking\FinTs\Support\SepaIdentifier;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DirectDebitService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly SepaXmlService $xml,
        private readonly CapabilityService $capabilities,
        private readonly OwnerScope $owners,
    ) {}

    public function submit(BankDirectDebit $debit, ?Model $actor = null, ?string $returnUrl = null): ScaOutcome
    {
        $claimed = DB::transaction(function () use ($debit): array|ScaOutcome {
            $locked = BankDirectDebit::query()->whereKey($debit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Submitted) {
                return new ScaOutcome(ScaSessionState::Done);
            }

            if ($locked->status === PaymentStatus::Ambiguous
                || $locked->status === PaymentStatus::Initiating
                || $locked->status->isInteractive()) {
                throw new AmbiguousSubmissionException(__('filament-accounting::banking/fints/errors.already_in_progress'));
            }

            $account = BankAccount::query()->whereKey($locked->accounting_bank_account_id)->lockForUpdate()->firstOrFail();
            $connection = $account->connection()->lockForUpdate()->firstOrFail();
            if (! $connection instanceof BankConnection) {
                throw new FintsValidationException('Missing bank connection.');
            }

            $this->owners->assertMatches($connection);
            if ($locked->legal_entity_id !== $account->legal_entity_id) {
                throw new FintsValidationException('Direct debit and bank account belong to different legal entities.');
            }
            $this->lockAndValidateMandate($locked, $connection);
            $this->validate($locked, $account);

            $locked->status = PaymentStatus::Initiating;
            $locked->error_message = null;
            $locked->error_code = null;
            $locked->save();

            return [$locked->fresh() ?? $locked, $account->fresh() ?? $account, $connection->fresh() ?? $connection];
        });

        if ($claimed instanceof ScaOutcome) {
            return $claimed;
        }

        [$debit, $account, $connection] = $claimed;

        try {
            $client = $this->factory->make($connection);
            $xml = $this->xml->directDebitXml($debit, $account, $this->capabilities->sepaPainSchemas($connection, $client));
            $action = SendSEPADirectDebit::create($account->toSepaAccount(), $xml);

            return $this->sca->execute(
                $connection,
                $action,
                ScaOperationType::DirectDebit,
                $client,
                $debit,
                $returnUrl,
                $actor,
            );
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e);
            $status = $mapped instanceof AmbiguousSubmissionException
                ? PaymentStatus::Ambiguous
                : PaymentStatus::Failed;

            DB::transaction(function () use ($debit, $mapped, $status): void {
                $locked = BankDirectDebit::query()->whereKey($debit->getKey())->lockForUpdate()->first();
                if (! $locked instanceof BankDirectDebit || $locked->status === PaymentStatus::Submitted) {
                    return;
                }

                $locked->status = $status;
                $locked->error_message = $mapped->userMessage();
                $locked->save();
            });

            event(new BankDirectDebitFailed($debit->uuid, $connection->id, $mapped->userMessage()));

            throw $mapped;
        }
    }

    private function lockAndValidateMandate(BankDirectDebit $debit, BankConnection $connection): void
    {
        if ($debit->direct_debit_mandate_id === null) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate_active'));
        }

        $mandate = DirectDebitMandate::query()
            ->whereKey($debit->direct_debit_mandate_id)
            ->lockForUpdate()
            ->first();

        if (! $mandate instanceof DirectDebitMandate) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate'));
        }

        if ($mandate->legal_entity_id !== $connection->legal_entity_id
            || $debit->creditor_profile_id !== $mandate->creditor_profile_id) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate'));
        }

        $debit->setRelation('mandate', $mandate);

        $blockingStatuses = [
            PaymentStatus::Initiating->value,
            PaymentStatus::AwaitingVop->value,
            PaymentStatus::AwaitingTan->value,
            PaymentStatus::AwaitingDecoupledConfirmation->value,
            PaymentStatus::WaitingBank->value,
            PaymentStatus::Ambiguous->value,
        ];

        $hasConcurrentSubmission = BankDirectDebit::query()
            ->where('direct_debit_mandate_id', $mandate->id)
            ->where($debit->getKeyName(), '!=', $debit->getKey())
            ->whereIn('status', $blockingStatuses)
            ->exists();

        if ($hasConcurrentSubmission) {
            throw new AmbiguousSubmissionException(__('filament-accounting::banking/fints/errors.already_in_progress'));
        }
    }

    private function validate(BankDirectDebit $debit, BankAccount $account): void
    {
        $connection = $account->connection;
        if (! $account->isUsable()
            || ! $connection instanceof BankConnection
            || ! $this->capabilities->supportsDirectDebitScheme($connection, $debit->scheme)) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.unsupported_capability'));
        }

        if (! SepaIdentifier::isValidCreditorIdentifier((string) $debit->creditor_identifier)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.creditor_identifier'));
        }

        if (! SepaIdentifier::isValidMandateReference((string) $debit->mandate_id)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate_reference'));
        }

        if (! Iban::isValid((string) $debit->debtor_iban)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.iban'));
        }

        if (! Iban::isValidBic($debit->debtor_bic)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.bic'));
        }

        if (! Money::isPositive((string) $debit->amount)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.amount'));
        }

        if ($debit->currency !== 'EUR') {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.direct_debit_currency'));
        }

        if ($debit->mandate_signed_on === null || $debit->requested_collection_date === null) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate'));
        }

        if ($debit->requested_collection_date->isBefore(today())) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.collection_date'));
        }

        foreach ([$debit->sepa_message_id, $debit->payment_information_id] as $identifier) {
            if (! is_string($identifier) || $identifier === '' || strlen($identifier) > 35) {
                throw new FintsValidationException(__('filament-accounting::banking/fints/validation.sepa_identifier'));
            }
        }

        if (filled($debit->end_to_end_id) && $debit->end_to_end_id !== 'NOTPROVIDED' && strlen((string) $debit->end_to_end_id) > 35) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.sepa_identifier'));
        }

        $mandate = $debit->mandate;
        if ($mandate !== null) {
            if ($mandate->status !== DirectDebitMandateStatus::Active) {
                throw new FintsValidationException(__('filament-accounting::banking/fints/validation.mandate_active'));
            }
            if ($debit->scheme === DirectDebitScheme::B2b && $mandate->debtor_bank_confirmed_at === null) {
                throw new FintsValidationException(__('filament-accounting::banking/fints/validation.b2b_bank_confirmation'));
            }
        }
    }
}
