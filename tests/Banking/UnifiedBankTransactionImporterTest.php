<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Banking\Services\UnifiedBankTransactionImporter;
use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\BankTransactionSourceVersion;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UnifiedBankTransactionImporterTest extends TestCase
{
    #[Test]
    public function pending_booked_and_storno_updates_share_one_transaction_and_append_source_versions(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $importer = app(UnifiedBankTransactionImporter::class);
        $base = [
            'amountMinor' => 4250,
            'currency' => 'EUR',
            'driverKey' => 'ignored-public-driver',
            'sourceAccountExternalId' => $account->external_account_id,
            'counterpartyName' => 'Acme GmbH',
            'counterpartyIban' => 'DE02120300000000202051',
            'purpose' => 'Invoice 42',
            'endToEndId' => 'EREF-42',
        ];

        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'pending-42',
            'sourceStatus' => 'pending',
            'sourcePayload' => ['fingerprint' => 'pending-42', 'status' => 'pending'],
            'sourceHash' => hash('sha256', 'pending'),
        ]))]);
        $transactionId = BankStatementLine::query()->sole()->getKey();

        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'booked-42',
            'bookingDate' => '2026-09-02',
            'sourceStatus' => 'booked',
            'sourcePayload' => ['fingerprint' => 'booked-42', 'status' => 'booked'],
            'sourceHash' => hash('sha256', 'booked'),
        ]))]);
        $importer->import($account, [new BankStatementLineData(...array_merge($base, [
            'externalId' => 'storno-42',
            'bookingDate' => '2026-09-03',
            'sourceStatus' => 'storno',
            'sourcePayload' => ['fingerprint' => 'storno-42', 'status' => 'storno'],
            'sourceHash' => hash('sha256', 'storno'),
        ]))]);

        $transaction = BankStatementLine::query()->sole();
        $this->assertSame($transactionId, $transaction->getKey());
        $this->assertSame('storno-42', $transaction->external_id);
        $this->assertSame(StatementLineStatus::Storno, $transaction->source_status);
        $this->assertSame(3, BankTransactionSourceVersion::query()
            ->where('bank_transaction_id', $transaction->getKey())
            ->count());
        $this->assertSame(
            ['pending', 'booked', 'storno'],
            BankTransactionSourceVersion::query()
                ->where('bank_transaction_id', $transaction->getKey())
                ->orderBy('version')
                ->pluck('source_status')
                ->all(),
        );
    }

    #[Test]
    public function identical_retries_do_not_duplicate_transactions_or_source_evidence(): void
    {
        $account = $this->makeBankAccount($this->makeEntity());
        $data = new BankStatementLineData(
            externalId: 'stable-1',
            amountMinor: 1000,
            currency: 'EUR',
            driverKey: 'anything',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: '2026-09-03',
            sourceStatus: 'booked',
            purpose: 'Stable',
            sourcePayload: ['fingerprint' => 'stable-1', 'raw' => 'raw-bank-payload'],
            sourceHash: hash('sha256', 'stable-source'),
        );
        $importer = app(UnifiedBankTransactionImporter::class);

        $importer->import($account, [$data]);
        $importer->import($account, [$data]);

        $this->assertSame(1, BankStatementLine::query()->count());
        $this->assertSame(1, BankTransactionSourceVersion::query()->count());
        $this->assertSame('raw-bank-payload', BankTransactionSourceVersion::query()->sole()->raw_payload);
    }

    #[Test]
    public function a_material_bank_change_after_posting_preserves_the_posted_values_and_marks_review(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $account = $this->makeBankAccount($entity);
        $importer = app(UnifiedBankTransactionImporter::class);
        $importer->import($account, [new BankStatementLineData(
            externalId: 'posted-1',
            amountMinor: -1000,
            currency: 'EUR',
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: '2026-09-03',
            sourceStatus: 'booked',
            purpose: 'Office',
            sourcePayload: ['fingerprint' => 'posted-1', 'amount' => '-10.00'],
            sourceHash: hash('sha256', 'version-1'),
        )]);
        $transaction = BankStatementLine::query()->sole();
        $expenseAccountId = (int) AccountRoleAssignment::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('role', AccountRole::Expense)
            ->value('ledger_account_id');
        app(AssignStatementLine::class)->handle($transaction, [
            'purpose' => SplitPurpose::LedgerAccount->value,
            'ledger_account_id' => $expenseAccountId,
        ]);

        $importer->import($account, [new BankStatementLineData(
            externalId: 'posted-1',
            amountMinor: -1100,
            currency: 'EUR',
            driverKey: 'fints',
            sourceAccountExternalId: $account->external_account_id,
            bookingDate: '2026-09-03',
            sourceStatus: 'booked',
            purpose: 'Office corrected',
            sourcePayload: ['fingerprint' => 'posted-1', 'amount' => '-11.00'],
            sourceHash: hash('sha256', 'version-2'),
        )]);

        $fresh = $transaction->fresh();
        $this->assertSame(-1000, $fresh?->amount_minor);
        $this->assertSame('Office', $fresh?->purpose);
        $this->assertTrue($fresh?->needs_review);
        $this->assertSame('source_changed_after_posting', $fresh?->review_reason['code']);
        $this->assertSame(2, BankTransactionSourceVersion::query()->count());
    }
}
