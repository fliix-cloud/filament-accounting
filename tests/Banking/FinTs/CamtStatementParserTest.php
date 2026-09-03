<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use Fhp\Action\GetStatementOfAccount;
use Fhp\Model\SEPAAccount;
use FilamentAccounting\Banking\FinTs\Actions\GetCamtStatementOfAccount;
use FilamentAccounting\Banking\FinTs\Services\StatementActionFactory;
use FilamentAccounting\Banking\FinTs\Support\CamtStatementParser;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeFintsClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CamtStatementParserTest extends TestCase
{
    #[Test]
    public function it_combines_booked_and_pending_camt_documents_without_losing_pending_entries(): void
    {
        $statement = (new CamtStatementParser)->parse(
            [$this->fixture('booked.xml')],
            [$this->fixture('pending.xml')],
        );
        $transactions = collect($statement->getStatements())
            ->flatMap(fn ($day): array => $day->getTransactions())
            ->values();

        $this->assertCount(2, $transactions);
        $this->assertTrue($transactions[0]->getBooked());
        $this->assertFalse($transactions[1]->getBooked());
        $this->assertSame('2026-09-04', $transactions[1]->getBookingDate()?->format('Y-m-d'));
        $this->assertSame(7.89, $transactions[1]->getAmount());
    }

    #[Test]
    public function it_prefers_pending_capable_camt_and_falls_back_to_upstream_mt940(): void
    {
        $account = new SEPAAccount;
        $account->setIban('DE89370400440532013000');
        $factory = new StatementActionFactory;
        $client = new FakeFintsClient;

        $this->assertInstanceOf(GetStatementOfAccount::class, $factory->create($client, $account, null, null));

        $client->camtStatementSchemas = ['urn:iso:std:iso:20022:tech:xsd:camt.052.001.08'];
        $action = $factory->create($client, $account, null, null);

        $this->assertInstanceOf(GetCamtStatementOfAccount::class, $action);
        $this->assertInstanceOf(GetCamtStatementOfAccount::class, unserialize(serialize($action)));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../Fixtures/camt/'.$name);
        $this->assertIsString($contents);

        return $contents;
    }
}
