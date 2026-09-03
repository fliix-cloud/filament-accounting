<?php

namespace FilamentAccounting\Banking\Services;

use DomainException;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Services\AuditLogger;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class LegacyBankingConsolidator
{
    /** @var list<string> */
    private const TABLES = [
        'fints_bank_connections',
        'fints_bank_accounts',
        'fints_bank_transactions',
        'fints_bank_transfers',
        'fints_bank_direct_debits',
        'fints_direct_debit_creditor_profiles',
        'fints_direct_debit_mandates',
        'fints_sca_sessions',
        'fints_sync_runs',
    ];

    public function __construct(
        private readonly UnifiedBankTransactionImporter $transactions,
        private readonly AuditLogger $audit,
        private readonly CanonicalJson $canonicalJson,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(): array
    {
        $counts = [];
        $present = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present[] = $table;
            $counts[$table] = DB::table($table)->count();
        }

        $blockers = [];
        $ownership = [];

        foreach (['fints_bank_connections', 'fints_direct_debit_creditor_profiles', 'fints_direct_debit_mandates'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (DB::table($table)->orderBy('id')->get() as $record) {
                $row = (array) $record;
                $entityId = $this->legalEntityId($table, $row);
                if ($entityId === null) {
                    $blockers[] = $this->blocker($table, $row, 'owner_mapping_missing_or_ambiguous');

                    continue;
                }

                $ownership[] = [
                    'source_table' => $table,
                    'source_id' => (int) $row['id'],
                    'legal_entity_id' => $entityId,
                ];
            }
        }

        if (Schema::hasTable('fints_direct_debit_mandates') && Schema::hasColumn('fints_direct_debit_mandates', 'party_bank_account_id')) {
            foreach (DB::table('fints_direct_debit_mandates')->orderBy('id')->get() as $record) {
                $row = (array) $record;
                if (! empty($row['party_bank_account_id'])) {
                    continue;
                }

                $entityId = $this->legalEntityId('fints_direct_debit_mandates', $row);
                $account = $entityId === null ? null : $this->partyBankAccount($row, $entityId);
                if (! $account instanceof PartyBankAccount) {
                    $blockers[] = $this->blocker('fints_direct_debit_mandates', $row, 'party_bank_account_mapping_missing_or_ambiguous');
                }
            }
        }

        $legacyTransactionHash = null;
        if (Schema::hasTable('fints_bank_transactions')) {
            $rows = DB::table('fints_bank_transactions')->orderBy('id')->get()
                ->map(fn (object $row): array => $this->transactionEvidence((array) $row))
                ->all();
            $legacyTransactionHash = hash('sha256', $this->canonicalJson->encode($rows));
        }

        return [
            'schema_version' => 1,
            'mode' => 'dry-run',
            'legacy_tables' => $present,
            'counts' => $counts,
            'ownership_mappings' => $ownership,
            'blockers' => $this->uniqueBlockers($blockers),
            'expected_targets' => [
                'bank_connections' => $counts['fints_bank_connections'] ?? 0,
                'bank_accounts' => $counts['fints_bank_accounts'] ?? 0,
                'bank_transactions' => $counts['fints_bank_transactions'] ?? 0,
                'transfers' => $counts['fints_bank_transfers'] ?? 0,
                'direct_debits' => $counts['fints_bank_direct_debits'] ?? 0,
                'mandates' => $counts['fints_direct_debit_mandates'] ?? 0,
                'reconciliations' => $this->count('accounting_reconciliations'),
                'settlements' => $this->count('accounting_settlements'),
            ],
            'legacy_transaction_hash' => $legacyTransactionHash,
        ];
    }

    /** @return array<string, mixed> */
    public function consolidate(): array
    {
        $report = $this->analyze();
        $blockers = $report['blockers'];
        if (is_array($blockers) && $blockers !== []) {
            throw new DomainException('Legacy consolidation is blocked by '.count($blockers).' unresolved mapping(s).');
        }

        return DB::transaction(function () use ($report): array {
            $this->applyOwnership('fints_bank_connections');
            $this->applyOwnership('fints_direct_debit_creditor_profiles');
            $this->applyOwnership('fints_direct_debit_mandates');
            $accountIds = $this->consolidateAccounts();
            $this->consolidateMandates();
            $this->updateDependentRows($accountIds);
            $this->consolidateTransactions($accountIds);

            $result = $this->analyze();
            $result['mode'] = 'apply';
            $result['applied'] = [
                'bank_accounts' => count($accountIds),
                'bank_transactions' => $this->count('accounting_bank_statement_lines', ['driver_key' => 'fints']),
                'source_versions' => $this->count('accounting_bank_transaction_source_versions'),
            ];
            $result['validation'] = $this->validateConsolidation($report, $accountIds);

            if (! $result['validation']['passed']) {
                throw new DomainException('Legacy consolidation target validation failed; all changes were rolled back.');
            }

            $evidenceHash = hash('sha256', $this->canonicalJson->encode([
                'before' => $report,
                'after' => $result,
            ]));

            if (Schema::hasTable('accounting_legacy_consolidation_runs')) {
                DB::table('accounting_legacy_consolidation_runs')->insert([
                    'uuid' => (string) Str::uuid(),
                    'source' => 'three-package-fints',
                    'status' => 'completed-read-only',
                    'evidence_hash' => $evidenceHash,
                    'report' => $this->canonicalJson->encode($result),
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (LegalEntity::query()->whereIn('id', array_values(array_unique(array_values($this->connectionEntityIds()))))->get() as $entity) {
                $this->audit->log($entity, 'migration.legacy-banking-consolidated', null, [
                    'evidence_hash' => $evidenceHash,
                    'legacy_tables_retained' => true,
                    'legacy_tables_status' => 'read-only',
                ]);
            }

            $result['evidence_hash'] = $evidenceHash;

            return $result;
        });
    }

    private function applyOwnership(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
            return;
        }

        foreach (DB::table($table)->orderBy('id')->get() as $record) {
            $row = (array) $record;
            $entityId = $this->legalEntityId($table, $row);
            if ($entityId !== null && (int) ($row['legal_entity_id'] ?? 0) !== $entityId) {
                DB::table($table)->where('id', $row['id'])->update(['legal_entity_id' => $entityId]);
            }
        }
    }

    /** @return array<int, int> */
    private function consolidateAccounts(): array
    {
        if (! Schema::hasTable('fints_bank_accounts')) {
            return [];
        }

        $map = [];
        foreach (DB::table('fints_bank_accounts')->orderBy('id')->get() as $record) {
            $row = (array) $record;
            $connection = (array) DB::table('fints_bank_connections')->where('id', $row['bank_connection_id'])->first();
            $entityId = (int) ($connection['legal_entity_id'] ?? 0);
            if ($entityId === 0) {
                throw new DomainException('A legacy bank account has no verified legal entity mapping.');
            }

            $externalId = (string) ($row['uuid'] ?? '');
            $account = AccountingBankAccount::query()
                ->where('legacy_fints_bank_account_id', $row['id'])
                ->orWhere(function ($query) use ($externalId): void {
                    $query->where('driver_key', 'fints')->where('external_account_id', $externalId);
                })
                ->first() ?? new AccountingBankAccount;

            if (! $account->exists) {
                $account->uuid = $externalId !== '' ? $externalId : (string) Str::uuid();
            }

            $account->fill([
                'legal_entity_id' => $entityId,
                'bank_connection_id' => (int) $row['bank_connection_id'],
                'legacy_fints_bank_account_id' => (int) $row['id'],
                'display_name' => (string) ($row['alias'] ?? $row['product_name'] ?? $row['iban'] ?? 'FinTS'),
                'iban' => $row['iban'] ?? null,
                'bic' => $row['bic'] ?? null,
                'currency' => (string) ($row['currency'] ?? 'EUR'),
                'driver_key' => 'fints',
                'external_account_id' => $externalId,
                'fingerprint' => $row['fingerprint'] ?? null,
                'account_number' => $row['account_number'] ?? null,
                'sub_account' => $row['sub_account'] ?? null,
                'bank_code' => $row['bank_code'] ?? null,
                'product_name' => $row['product_name'] ?? null,
                'account_holder_name' => $row['account_holder_name'] ?? null,
                'is_available' => (bool) ($row['is_available'] ?? $row['is_active'] ?? true),
                'is_enabled' => (bool) ($row['is_enabled'] ?? $row['is_active'] ?? true),
                'booked_balance_minor' => $this->minor($row['booked_balance'] ?? null, (string) ($row['currency'] ?? 'EUR')),
                'pending_balance_minor' => $this->minor($row['pending_balance'] ?? null, (string) ($row['currency'] ?? 'EUR')),
                'credit_line_minor' => $this->minor($row['credit_line'] ?? null, (string) ($row['currency'] ?? 'EUR')),
                'available_amount_minor' => $this->minor($row['available_amount'] ?? null, (string) ($row['currency'] ?? 'EUR')),
                'balance_at' => $row['balance_at'] ?? null,
                'last_balance_sync_at' => $row['last_balance_sync_at'] ?? null,
                'last_transaction_sync_at' => $row['last_transaction_sync_at'] ?? null,
            ]);
            $account->save();
            $map[(int) $row['id']] = (int) $account->getKey();
        }

        return $map;
    }

    private function consolidateMandates(): void
    {
        if (! Schema::hasTable('fints_direct_debit_mandates')) {
            return;
        }

        foreach (DB::table('fints_direct_debit_mandates')->orderBy('id')->get() as $record) {
            $row = (array) $record;
            $entityId = $this->legalEntityId('fints_direct_debit_mandates', $row);
            if ($entityId === null) {
                throw new DomainException('A legacy mandate has no verified legal entity mapping.');
            }

            $partyAccount = ! empty($row['party_bank_account_id'])
                ? PartyBankAccount::query()->where('legal_entity_id', $entityId)->find($row['party_bank_account_id'])
                : $this->partyBankAccount($row, $entityId);
            if (! $partyAccount instanceof PartyBankAccount) {
                throw new DomainException('A legacy mandate has no verified party bank account mapping.');
            }

            DB::table('fints_direct_debit_mandates')->where('id', $row['id'])->update([
                'legal_entity_id' => $entityId,
                'party_id' => $partyAccount->party_id,
                'party_bank_account_id' => $partyAccount->getKey(),
            ]);
        }
    }

    /** @param array<int, int> $accountIds */
    private function updateDependentRows(array $accountIds): void
    {
        foreach (['fints_bank_transfers', 'fints_bank_direct_debits'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (DB::table($table)->orderBy('id')->get() as $record) {
                $row = (array) $record;
                $accountId = $accountIds[(int) ($row['bank_account_id'] ?? 0)] ?? (int) ($row['accounting_bank_account_id'] ?? 0);
                $account = AccountingBankAccount::query()->find($accountId);
                if (! $account instanceof AccountingBankAccount) {
                    throw new DomainException("A legacy payment in {$table} has no verified bank account mapping.");
                }

                $updates = [
                    'legal_entity_id' => $account->legal_entity_id,
                    'accounting_bank_account_id' => $account->getKey(),
                ];
                if (Schema::hasColumn($table, 'amount_minor') && empty($row['amount_minor']) && isset($row['amount'])) {
                    $updates['amount_minor'] = $this->minor($row['amount'], (string) ($row['currency'] ?? 'EUR'));
                }
                DB::table($table)->where('id', $row['id'])->update($updates);
            }
        }

        foreach (['fints_sca_sessions', 'fints_sync_runs'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }

            foreach (DB::table($table)->orderBy('id')->get() as $record) {
                $row = (array) $record;
                $connectionEntity = DB::table('fints_bank_connections')->where('id', $row['bank_connection_id'])->value('legal_entity_id');
                $updates = ['legal_entity_id' => $connectionEntity];
                if ($table === 'fints_sync_runs' && Schema::hasColumn($table, 'accounting_bank_account_id')) {
                    $updates['accounting_bank_account_id'] = $accountIds[(int) ($row['bank_account_id'] ?? 0)] ?? null;
                }
                DB::table($table)->where('id', $row['id'])->update($updates);
            }
        }
    }

    /** @param array<int, int> $accountIds */
    private function consolidateTransactions(array $accountIds): void
    {
        if (! Schema::hasTable('fints_bank_transactions')) {
            return;
        }

        foreach ($accountIds as $legacyAccountId => $accountId) {
            $account = AccountingBankAccount::query()->findOrFail($accountId);
            $data = [];

            foreach (DB::table('fints_bank_transactions')->where('bank_account_id', $legacyAccountId)->orderBy('id')->get() as $record) {
                $row = (array) $record;
                $payload = $this->transactionEvidence($row);
                $direction = strtolower((string) ($row['direction'] ?? 'debit'));
                $incoming = $direction === 'credit';
                if ((bool) ($row['is_storno'] ?? false)) {
                    $incoming = ! $incoming;
                }
                $minor = abs($this->minor($row['amount'] ?? '0', (string) ($row['currency'] ?? 'EUR')) ?? 0);
                $amountMinor = $incoming ? $minor : -$minor;
                $externalId = (string) ($row['uuid'] ?? '');
                if ($externalId === '') {
                    $externalId = hash('sha256', implode('|', [
                        (string) $account->external_account_id,
                        (string) ($row['fingerprint'] ?? ''),
                        (string) ($row['occurrence'] ?? 1),
                    ]));
                }
                $counterpartyAccount = $this->stringOrNull($row['counterparty_account_number'] ?? null);
                $endToEndId = $this->stringOrNull($row['end_to_end_id'] ?? $this->structured($row)['EREF'] ?? null);

                $data[] = new BankStatementLineData(
                    externalId: $externalId,
                    amountMinor: $amountMinor,
                    currency: (string) ($row['currency'] ?? 'EUR'),
                    driverKey: 'fints',
                    sourceAccountExternalId: (string) $account->external_account_id,
                    bookingDate: $this->stringOrNull($row['booking_date'] ?? null),
                    valueDate: $this->stringOrNull($row['value_date'] ?? null),
                    sourceStatus: (bool) ($row['is_storno'] ?? false) ? 'storno' : ((bool) ($row['is_booked'] ?? true) ? 'booked' : 'pending'),
                    counterpartyName: $this->stringOrNull($row['counterparty_name'] ?? null),
                    counterpartyIban: $counterpartyAccount !== null && Sepa::isValidIban($counterpartyAccount) ? Sepa::normalizeIban($counterpartyAccount) : null,
                    counterpartyAccount: $counterpartyAccount,
                    purpose: $this->purpose($row),
                    endToEndId: $endToEndId,
                    paymentReference: $endToEndId,
                    sourcePayload: $payload,
                    sourceHash: hash('sha256', $this->canonicalJson->encode($payload)),
                    sourceCreatedAt: $this->stringOrNull($row['created_at'] ?? null),
                    sourceUpdatedAt: $this->stringOrNull($row['updated_at'] ?? null),
                );
            }

            if ($data !== []) {
                $this->transactions->import($account, $data);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function legalEntityId(string $table, array $row): ?int
    {
        $existing = (int) ($row['legal_entity_id'] ?? 0);
        if ($existing > 0 && LegalEntity::query()->whereKey($existing)->exists()) {
            return $existing;
        }

        if (! Schema::hasColumn($table, 'owner_type') || ! Schema::hasColumn($table, 'owner_id')) {
            return null;
        }

        $ownerType = $this->stringOrNull($row['owner_type'] ?? null);
        $ownerId = $this->stringOrNull($row['owner_id'] ?? null);
        if ($ownerType === null || $ownerId === null) {
            return null;
        }

        $ownerClass = Relation::getMorphedModel($ownerType) ?? $ownerType;
        if ($ownerClass !== LegalEntity::class && ! is_a($ownerClass, LegalEntity::class, true)) {
            return null;
        }

        $entity = LegalEntity::query()->find($ownerId);

        return $entity instanceof LegalEntity ? (int) $entity->getKey() : null;
    }

    /** @param array<string, mixed> $row */
    private function partyBankAccount(array $row, int $entityId): ?PartyBankAccount
    {
        $uuid = $this->stringOrNull($row['uuid'] ?? null);
        if ($uuid === null) {
            return null;
        }

        $matches = PartyBankAccount::query()
            ->where('legal_entity_id', $entityId)
            ->where('external_mandate_id', $uuid)
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function transactionEvidence(array $row): array
    {
        return [
            'transaction_uuid' => $row['uuid'] ?? null,
            'bank_account_id' => $row['bank_account_id'] ?? null,
            'fingerprint' => $row['fingerprint'] ?? null,
            'occurrence' => $row['occurrence'] ?? 1,
            'amount' => isset($row['amount']) ? (string) $row['amount'] : null,
            'currency' => $row['currency'] ?? 'EUR',
            'direction' => $row['direction'] ?? null,
            'booking_date' => $row['booking_date'] ?? null,
            'value_date' => $row['value_date'] ?? null,
            'is_booked' => (bool) ($row['is_booked'] ?? true),
            'is_storno' => (bool) ($row['is_storno'] ?? false),
            'booking_code' => $row['booking_code'] ?? null,
            'booking_text' => $row['booking_text'] ?? null,
            'counterparty_name' => $row['counterparty_name'] ?? null,
            'counterparty_account' => $row['counterparty_account_number'] ?? null,
            'purpose' => $this->purpose($row),
            'end_to_end_id' => $row['end_to_end_id'] ?? $this->structured($row)['EREF'] ?? null,
            'structured_description' => $this->structured($row),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<int, int>  $accountIds
     * @return array<string, mixed>
     */
    private function validateConsolidation(array $before, array $accountIds): array
    {
        $expectedTargets = is_array($before['expected_targets'] ?? null) ? $before['expected_targets'] : [];
        $expectedTransactions = $this->transactionVerificationRecords();
        $sourceIds = array_values(array_unique(array_column($expectedTransactions, 'source_id')));
        $legacyAccountIds = array_keys($accountIds);

        $actualTransactions = $sourceIds === []
            ? collect()
            : DB::table('accounting_bank_statement_lines')
                ->where('driver_key', 'fints')
                ->whereIn('external_id', $sourceIds)
                ->orderBy('external_id')
                ->get();

        $actualHashRecords = $actualTransactions
            ->map(static fn (object $row): array => [
                'source_id' => (string) $row->external_id,
                'source_hash' => (string) $row->source_hash,
            ])
            ->values()
            ->all();
        $expectedHashRecords = array_map(
            static fn (array $row): array => [
                'source_id' => $row['source_id'],
                'source_hash' => $row['source_hash'],
            ],
            $expectedTransactions,
        );
        usort($expectedHashRecords, static fn (array $left, array $right): int => [$left['source_id'], $left['source_hash']] <=> [$right['source_id'], $right['source_hash']]);
        usort($actualHashRecords, static fn (array $left, array $right): int => [$left['source_id'], $left['source_hash']] <=> [$right['source_id'], $right['source_hash']]);

        $expectedHash = hash('sha256', $this->canonicalJson->encode($expectedHashRecords));
        $actualHash = hash('sha256', $this->canonicalJson->encode($actualHashRecords));
        $preservedSourceVersions = 0;
        foreach ($expectedTransactions as $transaction) {
            if (DB::table('accounting_bank_transaction_source_versions')
                ->where('source_id', $transaction['source_id'])
                ->where('source_hash', $transaction['source_hash'])
                ->exists()) {
                $preservedSourceVersions++;
            }
        }

        $counts = [
            'bank_connections' => $this->countValidation(
                (int) ($expectedTargets['bank_connections'] ?? 0),
                $this->countNotNull('fints_bank_connections', 'legal_entity_id'),
            ),
            'bank_accounts' => $this->countValidation(
                (int) ($expectedTargets['bank_accounts'] ?? 0),
                $legacyAccountIds === [] ? 0 : DB::table('accounting_bank_accounts')
                    ->whereIn('legacy_fints_bank_account_id', $legacyAccountIds)
                    ->count(),
            ),
            'bank_transactions' => $this->countValidation(
                (int) ($expectedTargets['bank_transactions'] ?? 0),
                $actualTransactions->count(),
            ),
            'transfers' => $this->countValidation(
                (int) ($expectedTargets['transfers'] ?? 0),
                $this->countNotNull('fints_bank_transfers', 'accounting_bank_account_id'),
            ),
            'direct_debits' => $this->countValidation(
                (int) ($expectedTargets['direct_debits'] ?? 0),
                $this->countNotNull('fints_bank_direct_debits', 'accounting_bank_account_id'),
            ),
            'mandates' => $this->countValidation(
                (int) ($expectedTargets['mandates'] ?? 0),
                $this->countNotNull('fints_direct_debit_mandates', 'legal_entity_id'),
            ),
            'reconciliations' => $this->countValidation(
                (int) ($expectedTargets['reconciliations'] ?? 0),
                $this->count('accounting_reconciliations'),
            ),
            'settlements' => $this->countValidation(
                (int) ($expectedTargets['settlements'] ?? 0),
                $this->count('accounting_settlements'),
            ),
        ];
        $expectedAmounts = $this->transactionAmountTotals($expectedTransactions);
        $actualAmounts = $this->transactionAmountTotals($actualTransactions
            ->map(static fn (object $row): array => [
                'amount_minor' => (int) $row->amount_minor,
                'currency' => (string) $row->currency,
            ])
            ->all());
        $amountsPassed = $expectedAmounts === $actualAmounts;
        $hashesPassed = hash_equals($expectedHash, $actualHash)
            && $preservedSourceVersions === count($expectedTransactions);
        $countsPassed = ! in_array(false, array_column($counts, 'passed'), true);

        return [
            'passed' => $countsPassed && $amountsPassed && $hashesPassed,
            'counts' => $counts,
            'amounts_minor_by_currency' => [
                'expected' => $expectedAmounts,
                'actual' => $actualAmounts,
                'passed' => $amountsPassed,
            ],
            'source_hashes' => [
                'expected' => $expectedHash,
                'actual' => $actualHash,
                'preserved_source_versions' => $preservedSourceVersions,
                'expected_source_versions' => count($expectedTransactions),
                'passed' => $hashesPassed,
            ],
        ];
    }

    /** @return list<array{source_id: string, source_hash: string, amount_minor: int, currency: string}> */
    private function transactionVerificationRecords(): array
    {
        if (! Schema::hasTable('fints_bank_transactions') || ! Schema::hasTable('fints_bank_accounts')) {
            return [];
        }

        $accountExternalIds = DB::table('fints_bank_accounts')->pluck('uuid', 'id');
        $records = [];

        foreach (DB::table('fints_bank_transactions')->orderBy('id')->get() as $record) {
            $row = (array) $record;
            $payload = $this->transactionEvidence($row);
            $sourceId = (string) ($row['uuid'] ?? '');
            if ($sourceId === '') {
                $sourceId = hash('sha256', implode('|', [
                    (string) $accountExternalIds->get((int) ($row['bank_account_id'] ?? 0), ''),
                    (string) ($row['fingerprint'] ?? ''),
                    (string) ($row['occurrence'] ?? 1),
                ]));
            }

            $direction = strtolower((string) ($row['direction'] ?? 'debit'));
            $incoming = $direction === 'credit';
            if ((bool) ($row['is_storno'] ?? false)) {
                $incoming = ! $incoming;
            }
            $minor = abs($this->minor($row['amount'] ?? '0', (string) ($row['currency'] ?? 'EUR')) ?? 0);

            $records[] = [
                'source_id' => $sourceId,
                'source_hash' => hash('sha256', $this->canonicalJson->encode($payload)),
                'amount_minor' => $incoming ? $minor : -$minor,
                'currency' => strtoupper((string) ($row['currency'] ?? 'EUR')),
            ];
        }

        usort($records, static fn (array $left, array $right): int => [$left['source_id'], $left['source_hash']] <=> [$right['source_id'], $right['source_hash']]);

        return $records;
    }

    /**
     * @param  array<int, array{amount_minor: int, currency: string}>  $records
     * @return array<string, int>
     */
    private function transactionAmountTotals(array $records): array
    {
        $totals = [];
        foreach ($records as $record) {
            $currency = strtoupper($record['currency']);
            $totals[$currency] = ($totals[$currency] ?? 0) + $record['amount_minor'];
        }
        ksort($totals);

        return $totals;
    }

    /** @return array{expected: int, actual: int, passed: bool} */
    private function countValidation(int $expected, int $actual): array
    {
        return [
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $expected === $actual,
        ];
    }

    private function countNotNull(string $table, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->whereNotNull($column)->count();
    }

    /** @param array<string, mixed> $row */
    private function purpose(array $row): ?string
    {
        $purpose = $this->stringOrNull($row['purpose'] ?? null)
            ?? $this->stringOrNull($this->structured($row)['SVWZ'] ?? null);
        if ($purpose !== null) {
            return $purpose;
        }

        $parts = array_filter([
            $this->stringOrNull($row['description1'] ?? null),
            $this->stringOrNull($row['description2'] ?? null),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function structured(array $row): array
    {
        $value = $row['structured_description'] ?? [];
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function minor(mixed $amount, string $currency): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return ExactMoney::ofString((string) $amount, $currency)->minorAmount;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function blocker(string $table, array $row, string $reason): array
    {
        return [
            'table' => $table,
            'id' => (int) ($row['id'] ?? 0),
            'reason' => $reason,
            'owner_type' => $row['owner_type'] ?? null,
            'owner_id' => $row['owner_id'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $blockers @return list<array<string, mixed>> */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['table'].'#'.$blocker['id'].'#'.$blocker['reason']] = $blocker;
        }

        return array_values($unique);
    }

    /** @param array<string, mixed> $where */
    private function count(string $table, array $where = []): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /** @return array<int, int> */
    private function connectionEntityIds(): array
    {
        if (! Schema::hasTable('fints_bank_connections') || ! Schema::hasColumn('fints_bank_connections', 'legal_entity_id')) {
            return [];
        }

        return DB::table('fints_bank_connections')
            ->whereNotNull('legal_entity_id')
            ->pluck('legal_entity_id', 'id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
