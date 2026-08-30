<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Banking\Data\BankFeedImportResult;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankImportRun;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Support\Facades\DB;

final class ImportBankStatementLines
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<BankStatementLineData>  $lines
     */
    public function handle(AccountingBankAccount $account, array $lines, ?string $cursor = null): BankFeedImportResult
    {
        return DB::transaction(function () use ($account, $lines, $cursor): BankFeedImportResult {
            $upserted = 0;
            $skipped = 0;

            foreach ($lines as $data) {
                if ($data->sourceAccountExternalId && $data->sourceAccountExternalId !== $account->external_account_id) {
                    $skipped++;

                    continue;
                }

                $existing = BankStatementLine::query()
                    ->where('legal_entity_id', $account->legal_entity_id)
                    ->where('driver_key', $data->driverKey)
                    ->where('external_id', $data->externalId)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'legal_entity_id' => $account->legal_entity_id,
                    'bank_account_id' => $account->getKey(),
                    'driver_key' => $data->driverKey,
                    'external_id' => $data->externalId,
                    'source_account_external_id' => $data->sourceAccountExternalId ?? $account->external_account_id,
                    'amount_minor' => $data->amountMinor,
                    'currency' => strtoupper($data->currency),
                    'booking_date' => $data->bookingDate,
                    'value_date' => $data->valueDate,
                    'source_status' => StatementLineStatus::tryFrom($data->sourceStatus) ?? StatementLineStatus::Booked,
                    'counterparty_name' => $data->counterpartyName,
                    'counterparty_iban' => $data->counterpartyIban,
                    'counterparty_account' => $data->counterpartyAccount,
                    'purpose' => $data->purpose,
                    'end_to_end_id' => $data->endToEndId,
                    'payment_reference' => $data->paymentReference,
                    'source_payload' => $data->sourcePayload,
                    'source_hash' => $data->sourceHash,
                    'source_created_at' => $data->sourceCreatedAt,
                    'source_updated_at' => $data->sourceUpdatedAt,
                    'last_imported_at' => now(),
                ];

                if (! $existing instanceof BankStatementLine) {
                    $line = new BankStatementLine;
                    $line->fill($attributes);
                    $line->first_imported_at = now();
                    $line->save();
                    $upserted++;

                    continue;
                }

                $posted = $existing->reconciliations()
                    ->where('status', ReconciliationStatus::Posted)
                    ->exists();

                if ($posted && $this->materialChange($existing, $data)) {
                    $existing->needs_review = true;
                    $existing->review_reason = [
                        'code' => 'source_changed_after_posting',
                        'previous_hash' => $existing->source_hash,
                        'new_hash' => $data->sourceHash,
                    ];
                    $existing->last_imported_at = now();
                    $existing->save();
                    $upserted++;

                    continue;
                }

                $existing->fill($attributes);
                $existing->save();
                $upserted++;
            }

            $run = new BankImportRun;
            $run->fill([
                'legal_entity_id' => $account->legal_entity_id,
                'bank_account_id' => $account->getKey(),
                'driver_key' => $account->driver_key,
                'upserted_count' => $upserted,
                'cursor' => $cursor,
                'meta' => ['skipped' => $skipped],
            ]);
            $run->save();

            $entity = LegalEntity::query()->find($account->legal_entity_id);
            if ($entity instanceof LegalEntity) {
                $this->audit->log($entity, 'bank.imported', $account, [
                    'upserted' => $upserted,
                    'skipped' => $skipped,
                ]);
            }

            return new BankFeedImportResult($upserted, $skipped, $cursor);
        });
    }

    private function materialChange(BankStatementLine $existing, BankStatementLineData $data): bool
    {
        return $existing->amount_minor !== $data->amountMinor
            || strtoupper((string) $existing->currency) !== strtoupper($data->currency)
            || $existing->external_id !== $data->externalId;
    }
}
