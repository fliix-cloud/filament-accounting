<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use Fhp\Model\SEPAAccount;
use Fhp\Model\StatementOfAccount\Statement;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\Model\StatementOfAccount\Transaction as FhpTransaction;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateStatus;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateType;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitSequenceType;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Services\AccountSyncService;
use FilamentAccounting\Banking\FinTs\Services\SepaXmlService;
use FilamentAccounting\Banking\FinTs\Services\TransactionSyncService;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\BankTransactionSourceVersion;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class UnifiedFinTsIntegrationTest extends TestCase
{
    #[Test]
    public function account_discovery_uses_the_canonical_account_and_preserves_user_activation(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->connection($entity);
        $sepa = (new SEPAAccount)
            ->setIban('DE89370400440532013000')
            ->setBic('COBADEFFXXX')
            ->setAccountNumber('0532013000')
            ->setBlz('37040044');

        app(AccountSyncService::class)->persistAccounts($connection, [$sepa]);
        $account = AccountingBankAccount::query()->sole();
        $this->assertSame($entity->getKey(), $account->legal_entity_id);
        $this->assertSame($connection->getKey(), $account->bank_connection_id);
        $this->assertNotNull($account->ledger_account_id);
        $this->assertSame('fints', $account->source);

        $account->is_enabled = false;
        $account->save();
        app(AccountSyncService::class)->persistAccounts($connection, [$sepa]);
        $this->assertFalse($account->fresh()?->is_enabled);

        app(AccountSyncService::class)->persistAccounts($connection, []);
        $this->assertFalse($account->fresh()?->is_available);
    }

    #[Test]
    public function balances_remain_exact_minor_units_on_the_canonical_account(): void
    {
        $account = $this->account($this->connection($this->makeEntity()));
        $account->forceFill([
            'booked_balance_minor' => ExactMoney::ofString('1234.56', 'EUR')->minorAmount,
            'pending_balance_minor' => ExactMoney::ofString('-12.34', 'EUR')->minorAmount,
            'available_amount_minor' => ExactMoney::ofString('1300.00', 'EUR')->minorAmount,
            'balance_at' => now(),
            'last_balance_sync_at' => now(),
        ])->save();

        $fresh = $account->fresh();
        $this->assertSame(123456, $fresh?->booked_balance_minor);
        $this->assertSame(-1234, $fresh?->pending_balance_minor);
        $this->assertSame(130000, $fresh?->available_amount_minor);
        $this->assertNotNull($fresh?->last_balance_sync_at);
    }

    #[Test]
    public function transaction_sync_writes_directly_and_idempotently_to_canonical_transactions_with_source_versions(): void
    {
        $account = $this->account($this->connection($this->makeEntity()));
        $statement = new TestStatementOfAccount;
        $day = new Statement;
        $day->setDate(new \DateTime('2026-01-15'));
        $day->addTransaction($this->transaction(12.34));
        $day->addTransaction($this->transaction(12.34));
        $statement->push($day);

        $sync = app(TransactionSyncService::class);
        $this->assertSame(2, $sync->importStatement($account, $statement));
        $this->assertSame(0, $sync->importStatement($account, $statement));
        $this->assertSame(2, BankStatementLine::query()->where('bank_account_id', $account->getKey())->count());
        $this->assertSame(2, BankTransactionSourceVersion::query()->count());
        $this->assertCount(2, BankStatementLine::query()
            ->where('bank_account_id', $account->getKey())
            ->pluck('external_id')
            ->unique());
    }

    #[Test]
    public function credentials_and_sca_state_are_encrypted_and_sca_cleanup_removes_sensitive_state(): void
    {
        $entity = $this->makeEntity();
        $connection = $this->connection($entity);
        $rawConnection = DB::table('fints_bank_connections')->where('id', $connection->getKey())->first();
        $this->assertNotSame('login-id', $rawConnection?->username);
        $this->assertNotSame('secret-pin', $rawConnection?->pin);

        $session = StrongAuthenticationSession::query()->create([
            'bank_connection_id' => $connection->getKey(),
            'operation_type' => ScaOperationType::Login,
            'state' => ScaSessionState::NeedsTan,
            'encrypted_fints_state' => 'dialog-state',
            'encrypted_action' => 'action-state',
            'encrypted_challenge_text' => 'challenge',
            'expires_at' => now()->addMinutes(5),
        ]);
        $rawSession = DB::table('fints_sca_sessions')->where('id', $session->getKey())->first();
        $this->assertSame($entity->getKey(), $session->legal_entity_id);
        $this->assertNotSame('dialog-state', $rawSession?->encrypted_fints_state);
        $this->assertTrue($session->isOpen());

        $session->clearSensitiveState();
        $this->assertNull($session->fresh()?->encrypted_fints_state);
        $this->assertNull($session->fresh()?->encrypted_action);
        $this->assertNotNull($session->fresh()?->cleared_at);
    }

    #[Test]
    public function transfers_direct_debits_and_mandates_share_the_legal_entity_and_generate_sepa_xml(): void
    {
        $entity = $this->makeEntity();
        $account = $this->account($this->connection($entity));
        $party = $this->makeParty($entity, ['country_code' => 'DE', 'legal_name' => 'Kunde GmbH']);
        PartyAddress::query()->create([
            'party_id' => $party->getKey(),
            'line1' => 'Kundenweg 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country_code' => 'DE',
            'is_primary' => true,
        ]);
        $partyAccount = PartyBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'party_id' => $party->getKey(),
            'holder_name' => 'Kunde GmbH',
            'iban' => 'DE02120300000000202051',
            'bic' => 'BYLADEM1001',
            'is_primary' => true,
        ]);
        $profile = DirectDebitCreditorProfile::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'name' => 'Demo GmbH',
            'creditor_identifier' => 'DE98ZZZ09999999999',
            'country' => 'DE',
            'is_default' => true,
        ]);
        $mandate = DirectDebitMandate::query()->create([
            'party_bank_account_id' => $partyAccount->getKey(),
            'creditor_profile_id' => $profile->getKey(),
            'reference' => 'MANDATE-42',
            'scheme' => DirectDebitScheme::Core,
            'mandate_type' => DirectDebitMandateType::Recurring,
            'signed_on' => today()->subDays(10),
            'status' => DirectDebitMandateStatus::Active,
        ]);
        $transfer = BankTransfer::query()->create([
            'accounting_bank_account_id' => $account->getKey(),
            'recipient_name' => 'Lieferant GmbH',
            'recipient_iban' => 'DE02120300000000202051',
            'recipient_bic' => 'BYLADEM1001',
            'amount' => '10.25',
            'currency' => 'EUR',
            'purpose' => 'RE-42',
            'type' => TransferType::Sepa,
        ]);
        $debit = BankDirectDebit::query()->create([
            'accounting_bank_account_id' => $account->getKey(),
            'creditor_profile_id' => $profile->getKey(),
            'direct_debit_mandate_id' => $mandate->getKey(),
            'creditor_name' => $profile->name,
            'creditor_identifier' => $profile->creditor_identifier,
            'debtor_name' => $mandate->debtor_name,
            'debtor_iban' => $mandate->debtor_iban,
            'debtor_bic' => $mandate->debtor_bic,
            'amount' => '25.50',
            'mandate_id' => $mandate->reference,
            'mandate_signed_on' => $mandate->signed_on,
            'sequence_type' => DirectDebitSequenceType::First,
            'scheme' => DirectDebitScheme::Core,
            'requested_collection_date' => today()->addDays(5),
        ]);

        $xml = app(SepaXmlService::class);
        $transferXml = $xml->transferXml($transfer, $account);
        $debitXml = $xml->directDebitXml($debit, $account);

        $this->assertSame($entity->getKey(), $mandate->legal_entity_id);
        $this->assertSame($party->getKey(), $mandate->party_id);
        $this->assertSame('DE02120300000000202051', $mandate->debtor_iban);
        $this->assertSame(1025, $transfer->amount_minor);
        $this->assertSame(2550, $debit->amount_minor);
        $this->assertStringContainsString('pain.001', $transferXml);
        $this->assertStringContainsString('pain.008', $debitXml);
        $this->assertStringContainsString('MANDATE-42', $debitXml);
    }

    #[Test]
    public function fints_resources_are_strictly_isolated_by_the_shared_legal_entity_scope(): void
    {
        $first = $this->makeEntity(['legal_name' => 'First GmbH']);
        $firstConnection = $this->connection($first);
        $firstAccount = $this->account($firstConnection);
        $second = $this->makeEntity(['legal_name' => 'Second GmbH']);
        $secondConnection = $this->connection($second);
        $secondAccount = $this->account($secondConnection);

        $this->assertNull(BankConnectionResource::getEloquentQuery()->whereKey($firstConnection->getKey())->first());
        $this->assertNull(AccountingBankAccountResource::getEloquentQuery()->whereKey($firstAccount->getKey())->first());
        $this->assertNotNull(BankConnectionResource::getEloquentQuery()->whereKey($secondConnection->getKey())->first());
        $this->assertNotNull(AccountingBankAccountResource::getEloquentQuery()->whereKey($secondAccount->getKey())->first());
    }

    private function connection(LegalEntity $entity): BankConnection
    {
        return BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Testbank',
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function account(BankConnection $connection, array $overrides = []): AccountingBankAccount
    {
        return AccountingBankAccount::query()->create(array_merge([
            'legal_entity_id' => $connection->legal_entity_id,
            'bank_connection_id' => $connection->getKey(),
            'display_name' => 'Geschäftskonto',
            'external_account_id' => 'account-'.$connection->getKey(),
            'fingerprint' => 'fingerprint-'.$connection->getKey(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'account_number' => '0532013000',
            'bank_code' => '37040044',
            'currency' => 'EUR',
            'account_holder_name' => 'Demo GmbH',
            'is_available' => true,
            'is_enabled' => true,
        ], $overrides));
    }

    private function transaction(float $amount): FhpTransaction
    {
        $transaction = new FhpTransaction;
        $transaction->setBookingDate(new \DateTime('2026-01-15'));
        $transaction->setValutaDate(new \DateTime('2026-01-15'));
        $transaction->setAmount($amount);
        $transaction->setCreditDebit(FhpTransaction::CD_CREDIT);
        $transaction->setIsStorno(false);
        $transaction->setBookingCode('NTRF');
        $transaction->setBookingText('GUTSCHRIFT');
        $transaction->setDescription1('Invoice');
        $transaction->setDescription2('');
        $transaction->setStructuredDescription(['SVWZ' => 'Invoice 1']);
        $transaction->setBankCode('37040044');
        $transaction->setAccountNumber('123456');
        $transaction->setName('Acme GmbH');
        $transaction->setBooked(true);
        $transaction->setPN(1);
        $transaction->setTextKeyAddition(0);

        return $transaction;
    }
}

final class TestStatementOfAccount extends StatementOfAccount
{
    public function push(Statement $statement): self
    {
        $this->statements[] = $statement;

        return $this;
    }
}
