<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\JournalLine;
use FilamentAccounting\Models\TaxRuleVersion;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\PostDocument;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TaxEdgeCaseTest extends TestCase
{
    #[Test]
    public function mixed_tax_rates_produce_separate_tax_lines(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $party = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $party->getKey(),
            'issue_date' => '2026-09-11',
            'currency' => 'EUR',
            'lines' => [
                ['description' => 'Line 19%', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19'],
                ['description' => 'Line 7%', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-7'],
            ],
        ]);

        $this->assertSame(PostingStatus::Posted, $document->posting_status);

        $entry = JournalEntry::query()->where('source_type', 'document')
            ->where('source_id', (string) $document->getKey())->first();
        $this->assertNotNull($entry);

        $lines = JournalLine::query()->where('journal_entry_id', $entry->getKey())->get();

        // Receivable: debit 22600 (10000*1.19 + 10000*1.07)
        $this->assertNotNull($lines->firstWhere('debit_minor', 22600), 'Missing receivable debit');

        // Revenue net: credit 20000 (both lines share same account, combined by netByAccount)
        $this->assertNotNull($lines->firstWhere('credit_minor', 20000), 'Missing combined revenue credit');

        // Two separate tax credit lines
        $taxLines = $lines->filter(fn ($l) => $l->tax_code !== null);
        $this->assertCount(2, $taxLines, 'Two tax lines expected');
        $taxAmounts = $taxLines->pluck('credit_minor')->sort()->values();
        $this->assertSame([700, 1900], $taxAmounts->toArray());
        $taxCodes = $taxLines->pluck('tax_code')->sort()->values();
        $this->assertSame(['DE-19', 'DE-7'], $taxCodes->toArray());
    }

    #[Test]
    public function non_recoverable_purchase_tax_folds_into_expense(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $party = $this->makeParty($entity, ['is_customer' => false, 'is_supplier' => true]);

        $document = Document::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'type' => 'purchase_invoice',
            'direction' => 'incoming',
            'document_status' => 'draft',
            'posting_status' => 'unposted',
            'party_id' => $party->getKey(),
            'currency' => 'EUR',
            'number' => 'TAX-NREC-1',
            'issue_date' => '2026-09-11',
            'supply_date' => '2026-09-11',
        ]);

        $document->lines()->createMany([[
            'document_id' => $document->getKey(),
            'position' => 1, 'description' => 'Non-recoverable', 'quantity' => '1',
            'unit_price_minor' => 10000, 'net_minor' => 10000,
            'tax_code' => 'DE-19', 'tax_rule_version_id' => $this->taxVersionId($entity, 'DE-19'),
            'tax_rate_bp' => 1900, 'tax_minor' => 1900, 'gross_minor' => 11900,
            'tax_recoverable' => false,
        ]]);

        $document->net_minor = 10000;
        $document->tax_minor = 1900;
        $document->gross_minor = 11900;
        $document->document_status = DocumentStatus::Received;
        $document->save();

        app(PostDocument::class)->handle($document);

        $entry = JournalEntry::query()->where('source_type', 'document')
            ->where('source_id', (string) $document->getKey())->first();
        $lines = JournalLine::query()->where('journal_entry_id', $entry->getKey())->get();

        // Expense: debit 11900 (net + non-recoverable tax folded in)
        $expense = $lines->firstWhere('debit_minor', 11900);
        $this->assertNotNull($expense, 'Expense should fold non-recoverable tax');

        // Payable: credit 11900
        $this->assertNotNull($lines->firstWhere('credit_minor', 11900), 'Missing payable credit');

        // No separate tax line
        $this->assertSame(0, $lines->filter(fn ($l) => $l->tax_code !== null)->count(), 'No separate tax line for non-recoverable');
    }

    #[Test]
    public function credit_note_reverses_debit_credit_directions(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $party = $this->makeParty($entity);

        $document = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $party->getKey(),
            'issue_date' => '2026-09-11',
            'currency' => 'EUR',
            'type' => 'sales_credit_note',
            'lines' => [
                ['description' => 'Credit note line', 'quantity' => '1', 'unit_price_minor' => 10000, 'tax_code' => 'DE-19'],
            ],
        ]);

        $entry = JournalEntry::query()->where('source_type', 'document')
            ->where('source_id', (string) $document->getKey())->first();
        $lines = JournalLine::query()->where('journal_entry_id', $entry->getKey())->get();

        // Credit note: receivable CREDITED
        $this->assertNotNull($lines->firstWhere('credit_minor', 11900), 'Credit note should credit receivable');

        // Revenue DEBITED (net)
        $this->assertNotNull($lines->firstWhere('debit_minor', 10000), 'Credit note should debit revenue');

        // Tax DEBITED
        $this->assertNotNull($lines->firstWhere('debit_minor', 1900), 'Credit note should debit tax');
    }

    private function taxVersionId($entity, string $code): int
    {
        return (int) TaxRuleVersion::query()
            ->whereHas('taxCode', fn ($q) => $q->where('code', $code)->where('legal_entity_id', $entity->getKey()))
            ->where('valid_from', '<=', '2026-09-11')
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', '2026-09-11'))
            ->value('id');
    }
}
