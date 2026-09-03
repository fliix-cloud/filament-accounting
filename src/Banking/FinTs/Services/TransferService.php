<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\SendSEPARealtimeTransfer;
use Fhp\Action\SendSEPATransfer;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Events\BankTransferFailed;
use FilamentAccounting\Banking\FinTs\Exceptions\AmbiguousSubmissionException;
use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use FilamentAccounting\Banking\FinTs\Support\Iban;
use FilamentAccounting\Banking\FinTs\Support\Money;
use FilamentAccounting\Models\AccountingBankAccount as BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly SepaXmlService $xml,
        private readonly CapabilityService $capabilities,
        private readonly OwnerScope $owners,
    ) {}

    public function submit(BankTransfer $transfer, ?Model $actor = null, ?string $returnUrl = null): ScaOutcome
    {
        $claimed = DB::transaction(function () use ($transfer): array|ScaOutcome {
            /** @var BankTransfer $locked */
            $locked = BankTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Submitted) {
                return new ScaOutcome(ScaSessionState::Done, null, null);
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
                throw new FintsValidationException('Transfer and bank account belong to different legal entities.');
            }
            $this->validate($locked, $account);

            // Persist the claim before any network request. If PHP crashes or the
            // bank response is lost, a later request cannot silently resubmit it.
            $locked->status = PaymentStatus::Initiating;
            $locked->error_message = null;
            $locked->error_code = null;
            $locked->save();

            return [$locked->fresh() ?? $locked, $account->fresh() ?? $account, $connection->fresh() ?? $connection];
        });

        if ($claimed instanceof ScaOutcome) {
            return $claimed;
        }

        /** @var array{0: BankTransfer, 1: BankAccount, 2: BankConnection} $claimed */
        [$transfer, $account, $connection] = $claimed;

        try {
            $client = $this->factory->make($connection);
            $xml = $this->xml->transferXml($transfer, $account, $this->capabilities->sepaPainSchemas($connection, $client));
            $action = $transfer->type === TransferType::Realtime
                ? SendSEPARealtimeTransfer::create($account->toSepaAccount(), $xml, false)
                : SendSEPATransfer::create($account->toSepaAccount(), $xml);

            // StrongAuthenticationCoordinator is the single authority that
            // finalizes a successful payment, both for immediate completion and
            // for a later TAN / VoP / decoupled resume.
            return $this->sca->execute(
                $connection,
                $action,
                ScaOperationType::Transfer,
                $client,
                $transfer,
                $returnUrl,
                $actor,
            );
        } catch (\Throwable $e) {
            $mapped = ErrorMapper::map($e);
            $status = $mapped instanceof AmbiguousSubmissionException
                ? PaymentStatus::Ambiguous
                : PaymentStatus::Failed;

            DB::transaction(function () use ($transfer, $mapped, $status): void {
                /** @var BankTransfer|null $locked */
                $locked = BankTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->first();
                if (! $locked instanceof BankTransfer || $locked->status === PaymentStatus::Submitted) {
                    return;
                }

                $locked->status = $status;
                $locked->error_message = $mapped->userMessage();
                $locked->save();
            });

            event(new BankTransferFailed($transfer->uuid, $connection->id, $mapped->userMessage()));

            throw $mapped;
        }
    }

    private function validate(BankTransfer $transfer, BankAccount $account): void
    {
        if (! $account->isUsable()) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }

        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new FintsValidationException('Missing bank connection.');
        }
        if (! $this->capabilities->supportsTransferType($connection, $transfer->type)) {
            $this->refreshCapabilities($connection);
            $account->unsetRelation('connection');
            $connection = $account->connection;
        }

        if (! $connection instanceof BankConnection) {
            throw new FintsValidationException('Missing bank connection.');
        }

        if (! $this->capabilities->supportsTransferType($connection, $transfer->type)) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.unsupported_capability'));
        }

        if (! Iban::isValid((string) $transfer->recipient_iban)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.iban'));
        }

        if (! Iban::isValidBic($transfer->recipient_bic)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.bic'));
        }

        if (! Money::isPositive((string) $transfer->amount)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.amount'));
        }

        if (! filled($account->account_holder_name)) {
            throw new FintsValidationException(__('filament-accounting::banking/fints/validation.account_holder_name'));
        }
    }

    private function refreshCapabilities(BankConnection $connection): void
    {
        try {
            $client = $this->factory->make($connection);
            $this->capabilities->discover($connection, $client);
            app(FintsDialogStore::class)->remember($connection, $client);
        } catch (\Throwable) {
            // Keep the previously stored flags.
        }
    }
}
