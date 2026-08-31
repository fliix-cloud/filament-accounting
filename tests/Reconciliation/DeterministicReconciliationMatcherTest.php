<?php

namespace FilamentAccounting\Tests\Reconciliation;

use FilamentAccounting\Banking\Data\BankStatementLineData;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Services\ImportBankStatementLines;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\SuggestReconciliationMatches;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DeterministicReconciliationMatcherTest extends TestCase
{
    #[Test]
    public function it_matches_normalized_party_bank_accounts_instead_of_external_references(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());

        $accountOwner = $this->makeParty($entity, [
            'legal_name' => 'Account Owner GmbH',
        ]);
        $legacyReferenceParty = $this->makeParty($entity, [
            'legal_name' => 'Legacy Reference GmbH',
            'external_reference' => 'DE89370400440532013000',
        ]);

        PartyBankAccount::query()->create([
            'party_id' => $accountOwner->getKey(),
            'iban' => 'DE02 1203 0000 0000 2020 51',
            'is_primary' => true,
        ]);
        PartyBankAccount::query()->create([
            'party_id' => $accountOwner->getKey(),
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'is_primary' => false,
        ]);

        $matchingInvoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $accountOwner->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Matching account',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
            ]],
        ]);
        $legacyInvoice = app(IssueSalesInvoice::class)->handle($entity, [
            'party_id' => $legacyReferenceParty->getKey(),
            'issue_date' => '2026-03-01',
            'currency' => 'EUR',
            'lines' => [[
                'description' => 'Legacy reference',
                'quantity' => '1',
                'unit_price_minor' => 1000,
                'tax_code' => 'DE-19',
            ]],
        ]);

        $bank = $this->makeBankAccount($entity);
        app(ImportBankStatementLines::class)->handle($bank, [
            new BankStatementLineData(
                externalId: 'party-iban-match',
                amountMinor: 1190,
                currency: 'EUR',
                driverKey: 'synthetic',
                sourceAccountExternalId: 'acc-1',
                bookingDate: '2026-03-02',
                sourceStatus: 'booked',
                counterpartyIban: 'de89 3704 0044 0532 0130 00',
                purpose: 'payment',
            ),
        ]);

        $line = BankStatementLine::query()
            ->where('external_id', 'party-iban-match')
            ->firstOrFail();

        $this->assertSame('DE89370400440532013000', $line->counterparty_iban);

        $suggestions = app(SuggestReconciliationMatches::class)->handle($line);

        $this->assertCount(2, $suggestions);
        $this->assertSame($matchingInvoice->openItem->getKey(), $suggestions[0]->targetId);
        $this->assertContains('iban', $suggestions[0]->reasons);
        $this->assertFalse($suggestions[0]->ambiguous);

        $legacySuggestion = collect($suggestions)->first(
            fn ($suggestion): bool => $suggestion->targetId === $legacyInvoice->openItem->getKey(),
        );

        $this->assertNotNull($legacySuggestion);
        $this->assertNotContains('iban', $legacySuggestion->reasons);
        $this->assertGreaterThan($legacySuggestion->score, $suggestions[0]->score);
    }
}
