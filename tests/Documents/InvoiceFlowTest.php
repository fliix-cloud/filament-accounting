<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\PartyAddress;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Models\PartyTaxId;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InvoiceFlowTest extends TestCase
{
    #[Test]
    public function sales_invoice_lifecycle_updates_and_issues_the_same_draft_once(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $service = app(IssueSalesInvoice::class);

        $draft = $service->createDraft($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Initial line',
                'quantity' => '1',
                'unit_price' => '10.50',
                'tax_code' => 'DE-19',
            ]],
        ]);

        $this->assertSame(DocumentStatus::Draft, $draft->document_status);
        $this->assertSame(PostingStatus::Unposted, $draft->posting_status);
        $this->assertNull($draft->number);
        $this->assertSame(1050, $draft->lines->firstOrFail()->unit_price_minor);

        $updated = $service->updateDraft($draft, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-11',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Final line',
                'quantity' => '2',
                'unit_price' => '20.00',
                'tax_code' => 'DE-7',
            ]],
        ]);

        $issued = $service->issue($updated);
        $issuedAgain = $service->issue($issued);

        $this->assertSame($draft->getKey(), $issued->getKey());
        $this->assertSame($issued->getKey(), $issuedAgain->getKey());
        $this->assertSame(DocumentStatus::Issued, $issued->document_status);
        $this->assertSame(PostingStatus::Posted, $issued->posting_status);
        $this->assertSame(4000, $issued->net_minor);
        $this->assertSame(280, $issued->tax_minor);
        $this->assertSame(1, Document::query()->whereKey($draft->getKey())->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'document')->where('source_id', (string) $draft->getKey())->count());
    }

    #[Test]
    public function issuing_freezes_complete_seller_and_buyer_invoice_profiles(): void
    {
        $entity = $this->makeEntity([
            'address_line1' => 'Demo Street 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'vat_id' => 'DE123456789',
            'invoice_iban' => 'DE89370400440532013000',
            'default_payment_terms_days' => 30,
        ]);
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity, ['payment_terms_days' => 21]);
        PartyAddress::query()->create([
            'party_id' => $customer->getKey(),
            'line1' => 'Customer Road 2',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country_code' => 'DE',
            'is_primary' => true,
        ]);
        PartyTaxId::query()->create([
            'party_id' => $customer->getKey(),
            'type' => 'vat',
            'number' => 'DE987654321',
            'country_code' => 'DE',
        ]);
        PartyBankAccount::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'party_id' => $customer->getKey(),
            'holder_name' => 'Acme GmbH',
            'iban' => 'DE89370400440532013000',
            'is_primary' => true,
        ]);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $this->assertSame('Demo Street 1', $document->legal_entity_snapshot['address_line1']);
        $this->assertSame('DE123456789', $document->legal_entity_snapshot['vat_id']);
        $this->assertSame('Customer Road 2', $document->party_snapshot['addresses'][0]['line1']);
        $this->assertSame('DE987654321', $document->party_snapshot['vat_ids'][0]['number']);
        $this->assertSame('DE89370400440532013000', $document->party_snapshot['bank_accounts'][0]['iban']);
        $this->assertSame(21, $document->party_snapshot['payment_terms_days']);
    }

    #[Test]
    public function posting_a_sales_invoice_creates_ar_revenue_tax_and_open_item(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity, ['is_customer' => true]);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 10000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $this->assertSame(DocumentStatus::Issued, $document->document_status);
        $this->assertSame(PostingStatus::Posted, $document->posting_status);
        $this->assertSame(10000, $document->net_minor);
        $this->assertSame(1900, $document->tax_minor);
        $this->assertSame(11900, $document->gross_minor);
        $this->assertNotNull($document->openItem);
        $this->assertSame(OpenItemKind::Receivable, $document->openItem->kind);
        $this->assertSame(11900, $document->openItem->original_minor);
        $this->assertSame(PaymentStatus::Unpaid, $document->paymentStatus());

        $journal = JournalEntry::query()->where('source_type', 'document')->where('source_id', (string) $document->getKey())->first();
        $this->assertNotNull($journal);
        $this->assertSame(
            (int) $journal->lines->sum('base_debit_minor'),
            (int) $journal->lines->sum('base_credit_minor')
        );

        $ar = (int) AccountRoleAssignment::query()->where('legal_entity_id', $entity->getKey())->where('role', AccountRole::Receivable)->value('ledger_account_id');
        $this->assertSame(11900, (int) $journal->lines->firstWhere('ledger_account_id', $ar)?->debit_minor);
    }

    #[Test]
    public function posting_a_purchase_invoice_creates_expense_input_tax_and_payable(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Supplier AG']);

        $document = app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'ER-99',
            'issue_date' => '2026-03-11',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Hosting',
                'quantity' => '1',
                'unit_price_minor' => 20000,
                'tax_code' => 'DE-19',
                'account_role' => 'expense',
                'classification_code' => 'other_operating_expense',
                'classification_confirmed' => true,
                'tax_confirmed' => true,
            ]],
        ]);

        $this->assertSame(DocumentStatus::Received, $document->document_status);
        $this->assertSame(PostingStatus::Posted, $document->posting_status);
        $this->assertSame(OpenItemKind::Payable, $document->openItem->kind);
        $this->assertSame(23800, $document->gross_minor);
    }

    #[Test]
    public function purchase_drafts_cannot_be_received_without_manual_expense_and_tax_confirmation(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true]);
        $service = app(RegisterPurchaseInvoice::class);
        $draft = $service->createDraft($entity, [
            'party_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'UNCONFIRMED-1',
            'issue_date' => '2026-03-11',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Imported suggestion',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_code' => 'DE-19',
                'imported_tax_code' => 'DE-19',
            ]],
        ]);

        $this->expectException(DocumentException::class);
        $service->receive($draft);
    }

    #[Test]
    public function issued_commercial_fields_are_immutable(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $this->expectException(PostedRecordImmutableException::class);
        $document->gross_minor = 1;
        $document->save();
    }

    #[Test]
    public function payment_status_is_derived_from_open_item_remaining(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price_minor' => 10000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $this->assertSame(PaymentStatus::Unpaid, $document->paymentStatus());
        $this->assertSame(11900, $document->openItem->remainingMinor());
    }

    #[Test]
    public function unknown_and_date_gapped_tax_codes_never_fall_back_to_zero_percent(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        foreach ([
            ['tax_code' => 'UNKNOWN', 'issue_date' => '2026-03-10'],
            ['tax_code' => 'DE-19', 'issue_date' => '2006-12-31'],
        ] as $case) {
            try {
                app(IssueSalesInvoice::class)->handle($entity, [
                    'party_id' => $customer->getKey(),
                    'issue_date' => $case['issue_date'],
                    'currency' => 'EUR',
                    'lines' => [[
                        'description' => 'Consulting',
                        'quantity' => '1',
                        'unit_price_minor' => 1000,
                        'tax_code' => $case['tax_code'],
                    ]],
                ]);
                $this->fail('A missing tax rule must reject the invoice.');
            } catch (DocumentException) {
                $this->assertDatabaseMissing('accounting_documents', [
                    'legal_entity_id' => $entity->getKey(),
                    'issue_date' => $case['issue_date'],
                ]);
            }
        }
    }

    #[Test]
    public function explicit_zero_percent_is_valid_and_keeps_its_tax_treatment_snapshot(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Exempt service',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-0',
            ]],
        ]);

        $line = $document->lines->firstOrFail();
        $this->assertSame(0, $line->tax_rate_bp);
        $this->assertSame(0, $line->tax_minor);
        $this->assertSame('exempt', $line->tax_category);
        $this->assertNotNull($line->tax_rule_version_id);
    }

    #[Test]
    public function mixed_tax_invoice_posts_one_tax_line_per_rule_version(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-10',
            'currency' => 'EUR',
            'lines' => [
                ['description' => 'Standard', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19'],
                ['description' => 'Reduced', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-7'],
                ['description' => 'Exempt', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-0'],
            ],
        ]);

        $journal = JournalEntry::query()
            ->where('source_type', 'document')
            ->where('source_id', (string) $document->getKey())
            ->firstOrFail();
        $taxLines = $journal->lines->whereNotNull('tax_rule_version_id')->keyBy('tax_code');

        $this->assertCount(2, $taxLines);
        $this->assertSame(1900, (int) $taxLines->get('DE-19')?->credit_minor);
        $this->assertSame(700, (int) $taxLines->get('DE-7')?->credit_minor);
    }
}
