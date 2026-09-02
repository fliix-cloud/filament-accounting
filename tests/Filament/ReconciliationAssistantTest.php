<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Livewire\ReconciliationAssistant;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class ReconciliationAssistantTest extends TestCase
{
    #[Test]
    public function an_outgoing_transaction_defaults_to_purchase_invoice_in_the_four_part_assistant(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData(
                externalId: 'outgoing-modal-default',
                amountMinor: -11581,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-10',
                valueDate: '2026-03-11',
                sourceStatus: 'booked',
                counterpartyName: 'Vendor GmbH',
                counterpartyIban: 'DE02120300000000202051',
                purpose: 'Supplier payment',
            ),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'outgoing-modal-default')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid, 'context' => 'modal'])
            ->assertSet('assignmentType', 'purchase_invoice')
            ->assertSee(__('filament-accounting::fields.assignment_types.sales_invoice'))
            ->assertSee(__('filament-accounting::fields.assignment_types.purchase_invoice'))
            ->assertSee(__('filament-accounting::fields.assignment_types.posting_rule'))
            ->assertSee(__('filament-accounting::fields.assignment_types.split'))
            ->assertDontSee(__('filament-accounting::fields.invoice_or_bill'));
    }

    #[Test]
    public function an_exact_incoming_payment_is_posted_from_the_assistant(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Exact', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('assistant-exact', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme GmbH', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'assistant-exact')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectOpenItem', $invoice->openItem->getKey())
            ->call('finalize')
            ->assertHasNoErrors()
            ->assertDispatched('reconciliation-assistant-finalized');

        $this->assertSame(PaymentStatus::Paid, $invoice->fresh('openItem.settlements')->paymentStatus());
        $this->assertSame('suggestion_confirmed', Reconciliation::query()->sole()->match_meta['selection_source']);
    }

    #[Test]
    public function a_booked_transaction_can_be_posted_directly_to_a_ledger_account(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);
        $expense = LedgerAccount::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('code', '4900')
            ->firstOrFail();
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('assistant-direct-ledger', -2500, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'assistant-direct-ledger')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectAssignmentType', 'ledger_account')
            ->call('selectLedgerAccount', $expense->getKey())
            ->call('finalize')
            ->assertHasNoErrors()
            ->assertDispatched('reconciliation-assistant-finalized');

        $reconciliation = Reconciliation::query()->with('splits')->sole();
        $split = $reconciliation->splits->sole();

        $this->assertSame(SplitPurpose::LedgerAccount, $split->purpose);
        $this->assertSame($expense->getKey(), $split->ledger_account_id);
        $this->assertNotNull($reconciliation->journal_entry_id);
    }

    #[Test]
    public function a_direct_overpayment_is_blocked_before_and_during_posting(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Overpayment guard', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('assistant-overpay', 1200, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'assistant-overpay')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectOpenItem', $invoice->openItem->getKey())
            ->assertSee(__('filament-accounting::errors.settlement_exceeds_remaining'))
            ->call('finalize')
            ->assertHasErrors(['selectedOpenItemId']);

        $this->assertDatabaseCount('accounting_reconciliations', 0);
    }

    #[Test]
    public function three_sales_invoices_can_be_settled_by_one_exact_split(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoices = collect(range(1, 3))->map(fn (int $index) => app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-0'.$index,
            'currency' => 'EUR',
            'lines' => [['description' => 'Batch '.$index, 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]));
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('assistant-three-way', 3570, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'assistant-three-way')->firstOrFail();
        $allocations = $invoices->map(fn ($invoice): array => [
            'type' => 'sales_invoice',
            'target_id' => $invoice->openItem->getKey(),
            'amount' => '11.90',
            'reason' => null,
        ])->all();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectAssignmentType', 'split')
            ->set('allocations', $allocations)
            ->call('finalize')
            ->assertHasNoErrors();

        foreach ($invoices as $invoice) {
            $this->assertSame(PaymentStatus::Paid, $invoice->fresh('openItem.settlements')->paymentStatus());
        }
        $this->assertSame(3, Reconciliation::query()->sole()->splits()->count());
    }

    #[Test]
    public function an_outgoing_payment_can_split_between_a_purchase_invoice_and_bank_fees(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $supplier = $this->makeParty($entity, [
            'legal_name' => 'Vendor GmbH',
            'is_customer' => false,
            'is_supplier' => true,
        ]);
        $bank = $this->makeBankAccount($entity);
        $bill = app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'VENDOR-11581',
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Purchase', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19', 'account_role' => 'expense', 'classification_code' => 'other_operating_expense', 'classification_confirmed' => true, 'tax_confirmed' => true]],
        ]);
        $feeRule = PostingRule::query()->where('legal_entity_id', $entity->getKey())->where('code', 'bank_fees')->firstOrFail();
        $feeVersion = $feeRule->versionOn('2026-03-10');
        $this->assertNotNull($feeVersion);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('assistant-bill-fee', -1290, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'assistant-bill-fee')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectAssignmentType', 'split')
            ->set('allocations', [
                ['type' => 'purchase_invoice', 'target_id' => $bill->openItem->getKey(), 'amount' => '-11.90', 'reason' => null],
                ['type' => 'posting_rule', 'target_id' => $feeVersion->getKey(), 'amount' => '-1.00', 'reason' => 'Bank fee'],
            ])
            ->call('finalize')
            ->assertHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $bill->fresh('openItem.settlements')->paymentStatus());
        $this->assertSame(2, Reconciliation::query()->sole()->splits()->count());
    }

    #[Test]
    public function all_new_core_labels_exist_in_german_and_english(): void
    {
        foreach (['en', 'de'] as $locale) {
            app()->setLocale($locale);

            foreach (['sales_invoice', 'purchase_invoice', 'posting_rule', 'ledger_account', 'split'] as $type) {
                $key = 'filament-accounting::fields.assignment_types.'.$type;
                $this->assertNotSame($key, __($key));
            }

            foreach (['cancel', 'select', 'assign_and_post', 'use_remaining'] as $action) {
                $key = 'filament-accounting::actions.'.$action;
                $this->assertNotSame($key, __($key));
            }
        }
    }
}
