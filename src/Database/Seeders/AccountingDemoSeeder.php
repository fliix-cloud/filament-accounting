<?php

namespace FilamentAccounting\Database\Seeders;

use FilamentAccounting\Banking\FinTs\Enums\DirectDebitMandateType;
use FilamentAccounting\Banking\FinTs\Enums\DirectDebitScheme;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Services\SeedGermanProfile;
use Illuminate\Database\Seeder;

class AccountingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app(AccountingActorResolver::class)->resolve() === null) {
            throw new \LogicException('Sign in as an authorized demo user before running the accounting demo seeder.');
        }

        (new LegalEntity)->getConnection()->transaction(fn () => $this->seedDemo());
    }

    private function seedDemo(): void
    {
        $entity = LegalEntity::query()->firstOrCreate(
            ['legal_name' => 'Demo GmbH'],
            [
                'trading_name' => 'Demo GmbH',
                'country_code' => 'DE',
                'base_currency' => 'EUR',
                'locale' => 'de_DE',
                'timezone' => 'Europe/Berlin',
                'fiscal_year_start_month' => 1,
                'accounting_basis' => 'accrual',
                'vat_method' => 'accrual',
                'compliance_profile_key' => 'DE',
                'state' => 'active',
            ],
        );

        app(AccountingAuthorizer::class)->authorize('create_draft_invoices', $entity);
        app(AccountingAuthorizer::class)->authorize('register_purchase_invoices', $entity);
        if ($entity->wasRecentlyCreated) {
            app(SeedGermanProfile::class)->handle($entity);
        }

        $creditor = DirectDebitCreditorProfile::query()->firstOrCreate(
            [
                'legal_entity_id' => $entity->getKey(),
                'creditor_identifier_normalized' => 'DE98ZZZ09999999999',
            ],
            [
                'name' => 'Demo GmbH',
                'creditor_identifier' => 'DE98ZZZ09999999999',
                'country' => 'DE',
                'is_default' => true,
            ],
        );
        if (! $creditor->is_default) {
            $creditor->is_default = true;
            $creditor->save();
        }

        $customer = Party::query()->firstOrCreate(
            [
                'legal_entity_id' => $entity->getKey(),
                'legal_name' => 'Musterkunde AG',
            ],
            [
                'is_customer' => true,
                'is_supplier' => false,
                'kind' => 'organization',
                'country_code' => 'DE',
                'payment_terms_days' => 14,
                'default_currency' => 'EUR',
                'is_active' => true,
            ],
        );

        $supplier = Party::query()->firstOrCreate(
            [
                'legal_entity_id' => $entity->getKey(),
                'legal_name' => 'Bürobedarf Schmidt GmbH',
            ],
            [
                'is_customer' => false,
                'is_supplier' => true,
                'kind' => 'organization',
                'country_code' => 'DE',
                'payment_terms_days' => 30,
                'default_currency' => 'EUR',
                'is_active' => true,
            ],
        );

        $partyBankAccount = PartyBankAccount::query()->firstOrCreate(
            [
                'party_id' => $customer->getKey(),
                'iban' => 'DE89370400440532013000',
            ],
            [
                'legal_entity_id' => $entity->getKey(),
                'holder_name' => $customer->legal_name,
                'bic' => 'COBADEFFXXX',
                'is_primary' => true,
            ],
        );

        DirectDebitMandate::query()->firstOrCreate(
            [
                'legal_entity_id' => $entity->getKey(),
                'reference_normalized' => 'KD-MUSTER-1',
            ],
            [
                'creditor_profile_id' => $creditor->getKey(),
                'party_bank_account_id' => $partyBankAccount->getKey(),
                'reference' => 'KD-MUSTER-1',
                'scheme' => DirectDebitScheme::Core,
                'mandate_type' => DirectDebitMandateType::Recurring,
                'signed_on' => now()->subYear()->toDateString(),
            ],
        );

        app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(4)->toDateString(),
            'currency' => 'EUR',
            'idempotency_key' => 'demo-sales-1',
            'lines' => [[
                'description' => 'Beratung Q1',
                'quantity' => '1',
                'unit_price_minor' => 119000,
                'tax_code' => 'DE-19',
                'account_role' => 'revenue',
            ]],
        ]);

        app(RegisterPurchaseInvoice::class)->createDraft($entity, [
            'party_id' => $supplier->getKey(),
            'issue_date' => now()->subDays(5)->toDateString(),
            'receipt_date' => now()->subDays(4)->toDateString(),
            'supplier_invoice_number' => 'LS-4401',
            'currency' => 'EUR',
            'idempotency_key' => 'demo-purchase-1',
            'lines' => [[
                'description' => 'Büromaterial',
                'quantity' => '1',
                'unit_price_minor' => 21000,
                'tax_code' => 'DE-19',
                'account_role' => 'expense',
                'classification_code' => 'other_operating_expense',
            ]],
        ]);
    }
}
