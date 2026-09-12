<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Contracts\FintsClient;
use FilamentAccounting\Banking\FinTs\Contracts\FintsClientFactory;
use FilamentAccounting\Banking\FinTs\Enums\PaymentStatus;
use FilamentAccounting\Banking\FinTs\Enums\TransferType;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\BankDirectDebit;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Services\DirectDebitService;
use FilamentAccounting\Banking\FinTs\Services\TransferService;
use FilamentAccounting\Exceptions\AuthorizationException;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Tests\Fixtures\User;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * F3 / S-12: public money and suggestion services must carry their own ability
 * Gate as defense in depth, matching FinalizeReconciliation and
 * ReverseReconciliation. An undefined or denying Gate blocks the operation at
 * the service entry point, before any state change or bank call.
 */
class PaymentAuthorizationTest extends TestCase
{
    protected function defineAccountingGates(): void {}

    #[Test]
    public function transfer_submit_denies_without_create_bank_transfer_and_makes_no_claim(): void
    {
        $transfer = $this->transferFixture();
        $this->actingAs($this->makeUser());
        // Gate is undefined → deny.

        try {
            app(TransferService::class)->submit($transfer);
            $this->fail('Expected the missing transfer Gate to block submission.');
        } catch (AuthorizationException) {
            // Expected denial.
        }

        // No claim was persisted and no SCA session was opened.
        $this->assertSame(PaymentStatus::Draft, $transfer->fresh()?->status);
        $this->assertSame(0, StrongAuthenticationSession::query()->count());
    }

    #[Test]
    public function transfer_submit_allows_a_user_with_the_create_bank_transfer_gate(): void
    {
        Gate::define('accounting.bank.transfer.create', fn (User $user): bool => true);
        $transfer = $this->transferFixture();
        $this->actingAs($this->makeUser());
        $this->app->instance(FintsClientFactory::class, new class implements FintsClientFactory
        {
            public function make(BankConnection $connection, ?string $persistedInstance = null): FintsClient
            {
                throw new \RuntimeException('bank unreachable');
            }
        });

        try {
            app(TransferService::class)->submit($transfer);
            $this->fail('An unreachable bank must surface an error.');
        } catch (AuthorizationException) {
            $this->fail('A user with the transfer Gate must pass authorization.');
        } catch (\Throwable) {
            // Non-authorization failure: the Gate passed and submission reached the bank.
        }

        // The claim ran: the transfer left Draft and is now in an ambiguous state.
        $this->assertSame(PaymentStatus::Ambiguous, $transfer->fresh()?->status);
    }

    #[Test]
    public function direct_debit_submit_denies_without_create_bank_direct_debit(): void
    {
        $this->actingAs($this->makeUser());
        $debit = new BankDirectDebit;
        $denied = false;
        // Gate is undefined → deny before any validation or claim.
        try {
            app(DirectDebitService::class)->submit($debit);
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'Submission must be denied without the direct-debit Gate.');
    }

    #[Test]
    public function reconciliation_suggestions_deny_without_draft_reconciliation(): void
    {
        $this->actingAs($this->makeUser());
        $line = new BankStatementLine;
        $denied = false;
        // Gate is undefined → deny before suggestions are evaluated.
        try {
            app(SuggestReconciliationMatches::class)->handle($line);
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'Suggestions must be denied without the draft-reconciliation Gate.');
    }

    private function transferFixture(): BankTransfer
    {
        $entity = $this->makeEntity();
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
            'recipient_iban' => 'DE02120300000000202051',
            'recipient_bic' => 'BYLADEM1001',
            'amount' => '10.25',
            'currency' => 'EUR',
            'purpose' => 'RE-42',
            'type' => TransferType::Sepa,
            'status' => PaymentStatus::Draft,
        ]);
    }
}
