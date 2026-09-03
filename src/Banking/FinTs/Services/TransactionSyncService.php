<?php

namespace FilamentAccounting\Banking\FinTs\Services;

use Fhp\Action\GetStatementOfAccount;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\SyncStatus;
use FilamentAccounting\Banking\FinTs\Enums\SyncType;
use FilamentAccounting\Banking\FinTs\Events\BankTransactionsSynced;
use FilamentAccounting\Banking\FinTs\Exceptions\UnsupportedCapabilityException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankSyncRun;
use FilamentAccounting\Banking\FinTs\Support\TransactionFingerprint;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TransactionSyncService
{
    public function __construct(
        private readonly FintsClientFactory $factory,
        private readonly StrongAuthenticationCoordinator $sca,
        private readonly UnifiedBankTransactionImporter $importer,
    ) {}

    public function sync(
        AccountingBankAccount $account,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?Model $actor = null,
        ?string $returnUrl = null,
    ): ScaOutcome {
        $this->assertUsable($account);
        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
        $to ??= Carbon::today();
        $from ??= $account->last_transaction_sync_at
            ? Carbon::parse($account->last_transaction_sync_at)->subDays((int) config('filament-accounting.banking.fints.sync.incremental_overlap_days', 3))
            : Carbon::today()->subDays((int) config('filament-accounting.banking.fints.sync.initial_lookback_days', 90));
        $maxDays = (int) config('filament-accounting.banking.fints.sync.max_range_days', 90);
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > $maxDays) {
            $from = Carbon::parse($to)->subDays($maxDays);
        }

        $run = BankSyncRun::query()->create([
            'bank_connection_id' => $connection->id,
            'accounting_bank_account_id' => $account->id,
            'type' => SyncType::Transactions,
            'status' => SyncStatus::Running,
            'from_date' => $from,
            'to_date' => $to,
            'started_at' => now(),
        ]);
        $client = $this->factory->make($connection);
        $action = GetStatementOfAccount::create(
            $account->toSepaAccount(),
            Carbon::parse($from)->toDateTime(),
            Carbon::parse($to)->toDateTime(),
            false,
            true,
        );
        $outcome = $this->sca->execute(
            $connection,
            $action,
            ScaOperationType::SyncTransactions,
            $client,
            $run,
            $returnUrl,
            $actor,
        );

        if (! $outcome->isDone()) {
            $run->status = SyncStatus::RequiresAttention;
            $run->save();

            return $outcome;
        }

        $result = $this->importStatementDetailed($account, $action->getStatement());
        $this->markSyncCompleted($account, $run, $result);

        return $outcome;
    }

    public function importStatement(AccountingBankAccount $account, StatementOfAccount $statement): int
    {
        return $this->importStatementDetailed($account, $statement)['imported'];
    }

    /** @return array{imported: int, updated: int} */
    public function importStatementDetailed(AccountingBankAccount $account, StatementOfAccount $statement): array
    {
        $this->assertUsable($account);
        $before = BankStatementLine::query()->where('bank_account_id', $account->id)->count();
        $seen = [];
        $rows = [];

        foreach ($statement->getStatements() as $day) {
            foreach ($day->getTransactions() as $transaction) {
                $fingerprint = TransactionFingerprint::for($account, $transaction);
                $occurrence = ($seen[$fingerprint] ?? 0) + 1;
                $seen[$fingerprint] = $occurrence;
                $rows[] = $this->mapTransaction($account, $transaction, $fingerprint, $occurrence);
            }
        }

        $result = $this->importer->import($account, $rows);
        $after = BankStatementLine::query()->where('bank_account_id', $account->id)->count();
        $inserted = max(0, $after - $before);

        return [
            'imported' => $inserted,
            'updated' => max(0, $result->upserted - $inserted),
        ];
    }

    /** @param array{imported: int, updated: int} $result */
    public function markSyncCompleted(AccountingBankAccount $account, BankSyncRun $run, array $result): void
    {
        $connection = $account->connection;
        if (! $connection instanceof BankConnection) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
        $run->status = SyncStatus::Completed;
        $run->item_count = $result['imported'] + $result['updated'];
        $run->finished_at = now();
        $run->save();
        $account->last_transaction_sync_at = now();
        $account->save();
        $connection->last_transaction_sync_at = now();
        $connection->save();

        event(new BankTransactionsSynced($connection->id, $account->id, $run->item_count));
    }

    private function assertUsable(AccountingBankAccount $account): void
    {
        if (! $account->isUsable()) {
            throw new UnsupportedCapabilityException(__('filament-accounting::banking/fints/errors.account_not_usable'));
        }
    }

    private function mapTransaction(
        AccountingBankAccount $account,
        Transaction $transaction,
        string $fingerprint,
        int $occurrence,
    ): BankStatementLineData {
        $money = ExactMoney::ofString((string) $transaction->getAmount(), $account->currency ?: 'EUR');
        $incoming = $transaction->getCreditDebit() === Transaction::CD_CREDIT;
        if ($transaction->isStorno()) {
            $incoming = ! $incoming;
        }
        $amountMinor = $incoming ? abs($money->minorAmount) : -abs($money->minorAmount);
        $structured = $transaction->getStructuredDescription();
        ksort($structured);
        $purpose = $structured['SVWZ'] ?? $transaction->getMainDescription();
        $endToEndId = $transaction->getEndToEndID() ?: ($structured['EREF'] ?? null);
        $counterpartyAccount = $transaction->getAccountNumber();
        $counterpartyIban = Sepa::isValidIban($counterpartyAccount)
            ? Sepa::normalizeIban($counterpartyAccount)
            : null;
        $status = $transaction->isStorno()
            ? 'storno'
            : ($transaction->getBooked() ? 'booked' : 'pending');
        $payload = [
            'fingerprint' => $fingerprint,
            'occurrence' => $occurrence,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($money->currency),
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'booking_date' => $transaction->getBookingDate()?->format('Y-m-d'),
            'value_date' => $transaction->getValutaDate()?->format('Y-m-d'),
            'status' => $status,
            'booking_code' => $transaction->getBookingCode(),
            'booking_text' => $transaction->getBookingText(),
            'counterparty_name' => $transaction->getName(),
            'counterparty_account' => $counterpartyAccount,
            'counterparty_bank_code' => $transaction->getBankCode(),
            'description_1' => $transaction->getDescription1(),
            'description_2' => $transaction->getDescription2(),
            'structured_description' => $structured,
            'purpose' => $purpose,
            'end_to_end_id' => $endToEndId,
            'primanota' => (string) $transaction->getPN(),
            'text_key_addition' => (string) $transaction->getTextKeyAddition(),
        ];

        return new BankStatementLineData(
            externalId: hash('sha256', $fingerprint.'#'.$occurrence),
            amountMinor: $amountMinor,
            currency: $money->currency,
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: $transaction->getBookingDate()?->format('Y-m-d'),
            valueDate: $transaction->getValutaDate()?->format('Y-m-d'),
            sourceStatus: $status,
            counterpartyName: $transaction->getName(),
            counterpartyIban: $counterpartyIban,
            counterpartyAccount: $counterpartyAccount,
            purpose: $purpose,
            endToEndId: $endToEndId,
            paymentReference: $endToEndId,
            sourcePayload: $payload,
        );
    }
}
