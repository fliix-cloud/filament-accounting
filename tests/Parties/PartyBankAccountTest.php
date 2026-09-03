<?php

namespace FilamentAccounting\Tests\Parties;

use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Support\Sepa;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class PartyBankAccountTest extends TestCase
{
    #[Test]
    public function it_normalizes_customer_payment_details(): void
    {
        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity);

        $account = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'de89 3704 0044 0532 0130 00',
            'bic' => 'coba deff xxx',
            'is_primary' => true,
        ]);

        $this->assertSame($entity->getKey(), $account->legal_entity_id);
        $this->assertSame('DE89370400440532013000', $account->iban);
        $this->assertSame('COBADEFFXXX', $account->bic);
        $this->assertTrue(Sepa::isValidIban($account->iban));
        $this->assertSame($account->iban, $account->label());
    }

    #[Test]
    public function only_one_customer_payment_account_is_primary(): void
    {
        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity);

        $account = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'DE02120300000000202051',
            'is_primary' => true,
        ]);
        $second = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'DE89370400440532013000',
            'is_primary' => true,
        ]);

        $this->assertFalse($account->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue($customer->fresh('bankAccounts')->bankAccounts->contains($account));
        $this->assertCount(2, $customer->fresh('bankAccounts')->bankAccounts);
    }

    #[Test]
    public function mandate_data_is_owned_by_the_dedicated_mandate_table(): void
    {
        foreach (['mandate_reference', 'mandate_signed_on', 'mandate_scheme', 'mandate_type', 'mandate_status', 'external_mandate_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('accounting_party_bank_accounts', $column));
        }

        $this->assertTrue(Schema::hasTable('fints_direct_debit_mandates'));
    }

    #[Test]
    public function sepa_helpers_reject_invalid_iban(): void
    {
        $this->assertFalse(Sepa::isValidIban('DE00INVALID'));
        $this->assertTrue(Sepa::isValidBic(null));
    }

    #[Test]
    public function the_assignment_page_stays_registered_but_out_of_navigation(): void
    {
        $this->assertFalse(ReconciliationPage::shouldRegisterNavigation());
    }
}
