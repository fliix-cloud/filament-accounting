<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Exceptions\AmbiguousSubmissionException;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Services\TransferService;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PaymentSubmissionSafetyTest extends TestCase
{
    #[Test]
    public function an_ambiguous_transfer_is_never_resubmitted(): void
    {
        $factory = $this->createMock(FintsClientFactory::class);
        $factory->expects($this->never())->method('make');
        $this->app->instance(FintsClientFactory::class, $factory);
        $transfer = $this->transfer(PaymentStatus::Ambiguous);

        try {
            app(TransferService::class)->submit($transfer);
            $this->fail('An ambiguous transfer must require manual resolution.');
        } catch (AmbiguousSubmissionException) {
            $this->assertSame(PaymentStatus::Ambiguous, $transfer->fresh()?->status);
        }
    }

    #[Test]
    public function an_already_submitted_transfer_is_a_network_free_success(): void
    {
        $factory = $this->createMock(FintsClientFactory::class);
        $factory->expects($this->never())->method('make');
        $this->app->instance(FintsClientFactory::class, $factory);
        $transfer = $this->transfer(PaymentStatus::Submitted);

        $outcome = app(TransferService::class)->submit($transfer);

        $this->assertSame(ScaSessionState::Done, $outcome->state);
        $this->assertSame(PaymentStatus::Submitted, $transfer->fresh()?->status);
    }

    private function transfer(PaymentStatus $status): BankTransfer
    {
        $entity = $this->makeEntity();
        $connection = $this->connection($entity);
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
            'recipient_iban' => 'DE02120300000000202051',
            'recipient_bic' => 'BYLADEM1001',
            'amount' => '10.25',
            'currency' => 'EUR',
            'purpose' => 'RE-42',
            'type' => TransferType::Sepa,
            'status' => $status,
        ]);
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
}
