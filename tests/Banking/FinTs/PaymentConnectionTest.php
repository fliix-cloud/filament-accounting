<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Services\TransferService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * F9 / S-8 regression: with ACCOUNTING_DB_CONNECTION set, the money claim and
 * its terminal status writes must go to the accounting connection exclusively.
 * The default connection must never receive payment state, or a crash could
 * commit status on one connection and roll it back on the other.
 */
class PaymentConnectionTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function a_failed_submission_writes_ambiguity_on_the_accounting_connection_only(): void
    {
        $entity = $this->fixture();
        $transfer = $this->draftTransfer($entity);

        $this->assertSame(1, DB::connection('payment_accounting')->table('fints_bank_transfers')->count());
        $this->assertSame(0, DB::table('fints_bank_transfers')->count());

        // The bank is unreachable after the claim has committed. The runtime
        // failure is ambiguous; it must be reconciled on the model connection.
        $this->app->instance(FintsClientFactory::class, new class implements FintsClientFactory
        {
            public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient
            {
                throw new \RuntimeException('bank unreachable');
            }
        });

        try {
            app(TransferService::class)->submit($transfer);
            $this->fail('Expected the unreachable bank to surface an error.');
        } catch (\Throwable) {
            // Expected: the runtime failure maps to an ambiguous result.
        }

        // Claim + failure status are on the accounting connection; the default
        // connection never saw the write.
        $this->assertSame(1, DB::connection('payment_accounting')->table('fints_bank_transfers')->count());
        $this->assertSame(0, DB::table('fints_bank_transfers')->count());
        $row = (array) DB::connection('payment_accounting')->table('fints_bank_transfers')
            ->where('id', (int) $transfer->getKey())->first();
        $this->assertSame(PaymentStatus::Ambiguous->value, $row['status']);
        $this->assertNull($row['submitted_at']);
    }

    #[Test]
    public function a_claim_validation_failure_rolls_back_to_draft_without_leaking_to_the_default_connection(): void
    {
        $entity = $this->fixture();
        // Invalid IBAN fails local validation inside the claim transaction (before
        // any network call). The claim must roll back atomically: the transfer is
        // not left in an ambiguous state and the default connection stays empty.
        $transfer = $this->makeTransfer($entity, 'NOT-AN-IBAN');

        try {
            app(TransferService::class)->submit($transfer);
            $this->fail('Expected the invalid IBAN to be rejected.');
        } catch (\Throwable) {
            // Expected: local validation failure propagates from the claim.
        }

        // The claim rolled back: the transfer remains a Draft on the accounting
        // connection, with no error status and nothing on the default connection.
        $this->assertSame(1, DB::connection('payment_accounting')->table('fints_bank_transfers')->count());
        $this->assertSame(0, DB::table('fints_bank_transfers')->count());
        $row = (array) DB::connection('payment_accounting')->table('fints_bank_transfers')
            ->where('id', (int) $transfer->getKey())->first();
        $this->assertSame(PaymentStatus::Draft->value, $row['status']);
    }

    private function fixture(): LegalEntity
    {
        config()->set('database.connections.payment_accounting', config('database.connections.sqlite'));
        $schema = Schema::getFacadeRoot();
        Schema::swap(DB::connection('payment_accounting')->getSchemaBuilder());
        try {
            foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
                (require $path)->up();
            }
        } finally {
            Schema::swap($schema);
        }
        config()->set('filament-accounting.database.connection', 'payment_accounting');

        return $this->makeEntity();
    }

    private function draftTransfer(LegalEntity $entity): BankTransfer
    {
        return $this->makeTransfer($entity, 'DE02120300000000202051');
    }

    private function makeTransfer(LegalEntity $entity, string $recipientIban): BankTransfer
    {
        $this->actingAs($this->makeUser());
        $connection = BankConnection::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'display_name' => 'Testbank',
            'bank_code' => '12030000',
            'endpoint_url' => 'https://fints.example.test/cgi-bin/fints',
            'username' => 'login-id',
            'pin' => 'secret-pin',
        ]);
        $account = AccountingBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'bank_connection_id' => $connection->getKey(),
            'display_name' => 'Geschäftskonto',
            'external_account_id' => 'account-'.$connection->getKey(),
            'fingerprint' => 'fingerprint-'.$connection->getKey(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'currency' => 'EUR',
            'account_holder_name' => 'Demo GmbH',
            'is_available' => true,
            'is_enabled' => true,
        ]);

        return BankTransfer::query()->create([
            'accounting_bank_account_id' => $account->getKey(),
            'recipient_name' => 'Lieferant GmbH',
            'recipient_iban' => $recipientIban,
            'recipient_bic' => 'BYLADEM1001',
            'amount' => '10.25',
            'currency' => 'EUR',
            'purpose' => 'RE-42',
            'type' => TransferType::Sepa,
            'status' => PaymentStatus::Draft,
        ]);
    }
}
