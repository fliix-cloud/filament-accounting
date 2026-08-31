<?php

namespace FilamentAccounting\Tests\Parties;

use FilamentAccounting\Enums\PartyMandateScheme;
use FilamentAccounting\Enums\PartyMandateStatus;
use FilamentAccounting\Enums\PartyMandateType;
use FilamentAccounting\Events\PartyBankAccountChanged;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Support\Sepa;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class PartyBankAccountTest extends TestCase
{
    #[Test]
    public function it_normalizes_iban_and_stores_a_mandate_on_the_customer_account(): void
    {
        Event::fake([PartyBankAccountChanged::class]);

        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity);

        $account = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'de89 3704 0044 0532 0130 00',
            'bic' => 'coba deff xxx',
            'is_primary' => true,
            'mandate_reference' => 'KD-1001',
            'mandate_signed_on' => '2026-01-15',
        ]);

        $this->assertSame($entity->getKey(), $account->legal_entity_id);
        $this->assertSame('DE89370400440532013000', $account->iban);
        $this->assertSame('COBADEFFXXX', $account->bic);
        $this->assertSame('KD-1001', $account->mandate_reference);
        $this->assertSame('KD-1001', $account->mandate_reference_normalized);
        $this->assertSame(PartyMandateScheme::Core, $account->mandate_scheme);
        $this->assertSame(PartyMandateType::Recurring, $account->mandate_type);
        $this->assertSame(PartyMandateStatus::Active, $account->mandate_status);
        $this->assertTrue($account->hasMandate());
        $this->assertTrue(Sepa::isValidIban($account->iban));
        Event::assertDispatched(PartyBankAccountChanged::class);
    }

    #[Test]
    public function a_bank_account_without_a_mandate_is_just_payment_details(): void
    {
        Event::fake([PartyBankAccountChanged::class]);

        $entity = $this->makeEntity();
        $customer = $this->makeParty($entity);

        $account = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'DE02120300000000202051',
        ]);
        $second = PartyBankAccount::query()->create([
            'party_id' => $customer->getKey(),
            'iban' => 'DE89370400440532013000',
        ]);

        $this->assertFalse($account->hasMandate());
        $this->assertNull($account->mandate_reference);
        $this->assertNull($account->mandate_scheme);
        $this->assertNull($second->mandate_reference);
        $this->assertTrue($customer->fresh('bankAccounts')->bankAccounts->contains($account));
        $this->assertCount(2, $customer->fresh('bankAccounts')->bankAccounts);
    }

    #[Test]
    public function sepa_helpers_reject_invalid_iban_and_mandate_references(): void
    {
        $this->assertFalse(Sepa::isValidIban('DE00INVALID'));
        $this->assertFalse(Sepa::isValidMandateReference('/starts-with-slash'));
        $this->assertFalse(Sepa::isValidMandateReference('too//many'));
        $this->assertTrue(Sepa::isValidMandateReference('KD-1001'));
        $this->assertTrue(Sepa::isValidBic(null));
    }

    #[Test]
    public function the_assignment_page_stays_registered_but_out_of_navigation(): void
    {
        $this->assertFalse(ReconciliationPage::shouldRegisterNavigation());
    }
}
