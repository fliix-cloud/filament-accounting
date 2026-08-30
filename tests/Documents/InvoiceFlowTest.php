<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\OpenItemKind;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\AccountRoleAssignment;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class InvoiceFlowTest extends TestCase
{
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
            ]],
        ]);

        $this->assertSame(DocumentStatus::Received, $document->document_status);
        $this->assertSame(PostingStatus::Posted, $document->posting_status);
        $this->assertSame(OpenItemKind::Payable, $document->openItem->kind);
        $this->assertSame(23800, $document->gross_minor);
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
}
