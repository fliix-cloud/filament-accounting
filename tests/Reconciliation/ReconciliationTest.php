<?php

namespace FilamentAccounting\Tests\Reconciliation;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Enums\PaymentStatus;
use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Exceptions\ReconciliationException;
use FilamentAccounting\Filament\Pages\ReconciliationPage;
use FilamentAccounting\Filament\Support\DocumentSettlementActions;
use FilamentAccounting\Livewire\ReconciliationAssistant;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Services\AssignStatementLine;
use FilamentAccounting\Services\FinalizeReconciliation;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\RegisterPurchaseInvoice;
use FilamentAccounting\Services\ReverseReconciliation;
use FilamentAccounting\Services\SplitStatementLine;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Support\MoneyFormatter;
use FilamentAccounting\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class ReconciliationTest extends TestCase
{
    #[Test]
    public function direct_assignment_settles_one_target_and_a_smaller_payment_is_not_a_split(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Partial', 'quantity' => '1', 'unit_price_minor' => 100000, 'tax_code' => 'DE-19']],
        ]);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('direct-partial', 50000, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme GmbH', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'direct-partial')->firstOrFail();

        $reconciliation = app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ]);

        $this->assertSame('direct', $reconciliation->match_meta['mode']);
        $this->assertFalse($reconciliation->match_meta['amount_match']);
        $this->assertFalse($line->fresh('reconciliations.splits')->assignedAmountMatches());
        $this->assertCount(1, $reconciliation->splits);
        $this->assertSame(50000, $reconciliation->splits->sole()->amount_minor);
        $this->assertSame(PaymentStatus::PartiallyPaid, $invoice->fresh('openItem.settlements')->paymentStatus());
        $settlement = $invoice->fresh('settlements.reconciliation.statementLine')->settlements->sole();
        $this->assertTrue($settlement->reconciliation->statementLine->is($line));

        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $actions = DocumentSettlementActions::make($invoice->fresh('settlements.reconciliation.statementLine'));
        $this->assertCount(1, $actions);
        $this->assertStringContainsString(
            'accounting/reconcile?line='.$line->uuid,
            (string) $actions[0]->getUrl(),
        );
    }

    #[Test]
    public function split_operation_requires_at_least_two_allocations(): void
    {
        $this->expectException(ReconciliationException::class);

        app(SplitStatementLine::class)->handle(new BankStatementLine, [[
            'purpose' => SplitPurpose::Suspense->value,
            'amount_minor' => 100,
            'reason' => 'single target',
        ]]);
    }

    #[Test]
    public function split_allocations_reject_wrong_signs_and_duplicate_invoice_targets_server_side(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Duplicate target', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('wrong-sign-split', 100, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
            new BankStatementLineData('duplicate-target-split', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);

        try {
            app(SplitStatementLine::class)->handle(
                BankStatementLine::query()->where('external_id', 'wrong-sign-split')->firstOrFail(),
                [
                    ['purpose' => SplitPurpose::BankFee->value, 'amount_minor' => -1],
                    ['purpose' => SplitPurpose::BankFee->value, 'amount_minor' => 101],
                ],
            );
            $this->fail('Expected a wrong-sign split to be rejected.');
        } catch (ReconciliationException $exception) {
            $this->assertSame(__('filament-accounting::errors.allocation_sign_mismatch'), $exception->getMessage());
        }

        try {
            app(SplitStatementLine::class)->handle(
                BankStatementLine::query()->where('external_id', 'duplicate-target-split')->firstOrFail(),
                [
                    ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => 500, 'open_item_id' => $invoice->openItem->getKey()],
                    ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => 690, 'open_item_id' => $invoice->openItem->getKey()],
                ],
            );
            $this->fail('Expected a duplicate invoice target to be rejected.');
        } catch (ReconciliationException $exception) {
            $this->assertSame(__('filament-accounting::errors.duplicate_open_item_allocation'), $exception->getMessage());
        }
    }

    #[Test]
    public function split_assistant_starts_incomplete_and_does_not_treat_blank_amounts_as_zero(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('split-editor', 1000, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'split-editor')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectAssignmentType', 'split')
            ->assertSet('allocations.0.amount', '')
            ->assertSet('allocations.1.amount', '')
            ->assertSee(__('filament-accounting::errors.invalid_allocation_amount'))
            ->set('allocations.0.amount', '10.00')
            ->assertSet('allocations.0.amount', '10.00')
            ->assertSee(__('filament-accounting::errors.invalid_allocation_amount'));
    }

    #[Test]
    public function reconciliation_page_is_hidden_from_navigation(): void
    {
        $this->assertFalse(ReconciliationPage::shouldRegisterNavigation());
    }

    #[Test]
    public function direct_assignment_explains_a_partial_payment_before_posting(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Partial', 'quantity' => '1', 'unit_price_minor' => 100000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('warn-partial', 300, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme GmbH', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'warn-partial')->firstOrFail();

        Livewire::test(ReconciliationAssistant::class, ['line' => $line->uuid])
            ->call('selectAssignmentType', 'sales_invoice')
            ->call('selectOpenItem', $invoice->openItem->getKey())
            ->assertSee(__('filament-accounting::fields.partial_payment_notice', [
                'payment' => MoneyFormatter::format(300, 'EUR'),
                'open' => MoneyFormatter::format(119000, 'EUR'),
            ]));
    }

    #[Test]
    public function a_matching_direct_assignment_records_an_amount_match(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Full', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('full-match', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme GmbH', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'full-match')->firstOrFail();

        $reconciliation = app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ]);

        $this->assertTrue($reconciliation->match_meta['amount_match']);
        $this->assertTrue($line->fresh('reconciliations.splits.openItem')->assignedAmountMatches());
        $this->assertSame(PaymentStatus::Paid, $invoice->fresh('openItem.settlements')->paymentStatus());
    }

    #[Test]
    public function direct_assignment_rejects_open_items_with_the_opposite_payment_direction(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $supplier = $this->makeParty($entity, [
            'is_customer' => false,
            'is_supplier' => true,
            'legal_name' => 'Vendor GmbH',
        ]);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Receivable', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        $bill = app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'DIRECTION-1',
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Payable', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19', 'account_role' => 'expense', 'classification_code' => 'other_operating_expense', 'classification_confirmed' => true, 'tax_confirmed' => true]],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('wrong-incoming', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
            new BankStatementLineData('wrong-outgoing', -1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);

        $cases = [
            ['wrong-incoming', $bill->openItem->getKey()],
            ['wrong-outgoing', $invoice->openItem->getKey()],
        ];

        foreach ($cases as [$externalId, $openItemId]) {
            try {
                app(AssignStatementLine::class)->handle(
                    BankStatementLine::query()->where('external_id', $externalId)->firstOrFail(),
                    [
                        'purpose' => SplitPurpose::SettleOpenItem->value,
                        'open_item_id' => $openItemId,
                    ],
                );
                $this->fail('Expected an opposite-direction open item to be rejected.');
            } catch (ReconciliationException $exception) {
                $this->assertSame(
                    __('filament-accounting::errors.invalid_allocation_target'),
                    $exception->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function a_posting_rule_separates_gross_expense_and_input_tax(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);
        $rule = PostingRule::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('code', 'operating_expense')
            ->firstOrFail();
        $version = $rule->versionOn('2026-03-10');
        $this->assertNotNull($version);

        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('expense-tax', -11900, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Vendor GmbH'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'expense-tax')->firstOrFail();

        $reconciliation = app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::PostingRule->value,
            'posting_rule_version_id' => $version->getKey(),
        ]);
        $journal = $reconciliation->journalEntry->fresh('lines.ledgerAccount');
        $amounts = $journal->lines->mapWithKeys(fn ($line): array => [
            $line->ledgerAccount->code => [$line->debit_minor, $line->credit_minor, $line->tax_code],
        ])->all();

        $this->assertSame([0, 11900, null], $amounts['1200']);
        $this->assertSame([10000, 0, 'DE-19'], $amounts['4900']);
        $this->assertSame([1900, 0, 'DE-19'], $amounts['1576']);
    }

    #[Test]
    public function direct_assignment_rejects_an_open_item_in_another_currency(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'EUR invoice', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('usd-payment', 1190, 'USD', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'usd-payment')->firstOrFail();

        $this->expectException(ReconciliationException::class);
        $this->expectExceptionMessage(__('filament-accounting::errors.allocation_currency_mismatch'));

        app(AssignStatementLine::class)->handle($line, [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ]);
    }

    #[Test]
    public function a_reversed_line_can_be_assigned_again_with_a_new_version(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('reassign-1', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'reassign-1')->firstOrFail();
        $assignment = [
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'open_item_id' => $invoice->openItem->getKey(),
        ];

        $first = app(AssignStatementLine::class)->handle($line, $assignment);
        app(ReverseReconciliation::class)->handle($first, '2026-03-11', 'Wrong assignment');
        $freshLine = BankStatementLine::query()->findOrFail($line->getKey());
        $second = app(AssignStatementLine::class)->handle($freshLine, $assignment);

        $this->assertSame(ReconciliationStatus::Posted, $second->status);
        $this->assertSame(3, $second->version);
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
    }

    #[Test]
    public function it_finalizes_exact_partial_many_to_many_and_fee_splits(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $supplier = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true, 'legal_name' => 'Vendor GmbH']);
        $bank = $this->makeBankAccount($entity);

        $invoiceA = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 100000, 'tax_code' => 'DE-19']],
        ]);
        $invoiceB = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-02',
            'currency' => 'EUR',
            'lines' => [['description' => 'B', 'quantity' => '1', 'unit_price_minor' => 50000, 'tax_code' => 'DE-19']],
        ]);
        $bill = app(RegisterPurchaseInvoice::class)->handle($entity, [
            'party_id' => $supplier->getKey(),
            'supplier_invoice_number' => 'S-1',
            'issue_date' => '2026-03-03',
            'currency' => 'EUR',
            'lines' => [['description' => 'Bill', 'quantity' => '1', 'unit_price_minor' => 100000, 'tax_code' => 'DE-19', 'account_role' => 'expense', 'classification_code' => 'other_operating_expense', 'classification_confirmed' => true, 'tax_confirmed' => true]],
        ]);

        $importer = app(ImportBankStatementLines::class);
        $importer->handle($bank, [
            new BankStatementLineData('in-1', 178500, 'EUR', 'synthetic', 'acc-1', '2026-03-10', '2026-03-10', 'booked', 'Acme GmbH', null, null, $invoiceA->number.' '.$invoiceB->number),
            new BankStatementLineData('out-1', -121000, 'EUR', 'synthetic', 'acc-1', '2026-03-11', '2026-03-11', 'booked', 'Vendor GmbH', null, null, 'S-1 fee'),
        ]);

        $incoming = BankStatementLine::query()->where('external_id', 'in-1')->firstOrFail();
        $outgoing = BankStatementLine::query()->where('external_id', 'out-1')->firstOrFail();

        $finalizer = app(FinalizeReconciliation::class);
        $many = $finalizer->handle($incoming, [
            ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => 119000, 'open_item_id' => $invoiceA->openItem->getKey()],
            ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => 59500, 'open_item_id' => $invoiceB->openItem->getKey()],
        ]);
        $this->assertSame(ReconciliationStatus::Posted, $many->status);
        $this->assertSame(PaymentStatus::Paid, $invoiceA->fresh('openItem.settlements')->paymentStatus());
        $this->assertSame(PaymentStatus::Paid, $invoiceB->fresh('openItem.settlements')->paymentStatus());

        $fee = $finalizer->handle($outgoing, [
            ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => -119000, 'open_item_id' => $bill->openItem->getKey()],
            ['purpose' => SplitPurpose::BankFee->value, 'amount_minor' => -2000, 'reason' => 'bank fee'],
        ]);
        $this->assertSame(ReconciliationStatus::Posted, $fee->status);
        $this->assertSame(PaymentStatus::Paid, $bill->fresh('openItem.settlements')->paymentStatus());

        $partialInvoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-12',
            'currency' => 'EUR',
            'lines' => [['description' => 'C', 'quantity' => '1', 'unit_price_minor' => 100000, 'tax_code' => 'DE-19']],
        ]);
        $importer->handle($bank, [
            new BankStatementLineData('in-2', 50000, 'EUR', 'synthetic', 'acc-1', '2026-03-13', '2026-03-13', 'booked', 'Acme GmbH', null, null, $partialInvoice->number),
        ]);
        $partialLine = BankStatementLine::query()->where('external_id', 'in-2')->firstOrFail();
        $finalizer->handle($partialLine, [
            ['purpose' => SplitPurpose::SettleOpenItem->value, 'amount_minor' => 50000, 'open_item_id' => $partialInvoice->openItem->getKey()],
        ]);
        $this->assertSame(PaymentStatus::PartiallyPaid, $partialInvoice->fresh('openItem.settlements')->paymentStatus());
        $this->assertSame(69000, $partialInvoice->fresh('openItem.settlements')->openItem->remainingMinor());
    }

    #[Test]
    public function pending_lines_cannot_be_finalized_even_with_a_reason(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $bank = $this->makeBankAccount($entity);
        $line = BankStatementLine::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'bank_account_id' => $bank->getKey(),
            'source' => 'synthetic',
            'external_id' => 'pending-1',
            'amount_minor' => 100,
            'currency' => 'EUR',
            'booking_date' => '2026-03-01',
            'source_status' => StatementLineStatus::Pending,
        ]);

        $this->expectException(ReconciliationException::class);
        app(FinalizeReconciliation::class)->handle($line, [
            ['purpose' => SplitPurpose::Suspense->value, 'amount_minor' => 100, 'reason' => 'x'],
        ]);
    }

    #[Test]
    public function duplicate_finalize_is_idempotent(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $bank = $this->makeBankAccount($entity);
        $invoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 1000, 'tax_code' => 'DE-19']],
        ]);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('pay-1', 1190, 'EUR', 'synthetic', 'acc-1', '2026-03-10', null, 'booked', 'Acme', null, null, $invoice->number),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'pay-1')->firstOrFail();
        $splits = [[
            'purpose' => SplitPurpose::SettleOpenItem->value,
            'amount_minor' => 1190,
            'open_item_id' => $invoice->openItem->getKey(),
        ]];
        $first = app(FinalizeReconciliation::class)->handle($line, $splits, null, 'idem-1');
        $second = app(FinalizeReconciliation::class)->handle($line, $splits, null, 'idem-1');
        $this->assertTrue($first->is($second));
    }

    #[Test]
    public function matcher_marks_ambiguous_equal_scores(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $customer = $this->makeParty($entity);
        $a = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'A', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);
        $b = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $customer->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'B', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19']],
        ]);
        $bank = $this->makeBankAccount($entity);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData('amb-1', 11900, 'EUR', 'synthetic', 'acc-1', '2026-03-02', null, 'booked', 'Acme GmbH', null, null, 'payment'),
        ]);
        $line = BankStatementLine::query()->where('external_id', 'amb-1')->firstOrFail();
        $suggestions = app(SuggestReconciliationMatches::class)->handle($line);
        $this->assertNotEmpty($suggestions);
        $this->assertTrue($suggestions[0]->ambiguous);
        $this->assertNotSame($a->getKey(), $b->getKey());
    }
}
