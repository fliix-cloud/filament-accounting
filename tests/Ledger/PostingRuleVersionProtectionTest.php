<?php

namespace FilamentAccounting\Tests\Ledger;

use FilamentAccounting\Enums\ReconciliationStatus;
use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Enums\StatementLineStatus;
use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationSplit;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PostingRuleVersionProtectionTest extends TestCase
{
    #[Test]
    public function unused_version_can_be_updated(): void
    {
        $this->makeEntity();
        $version = PostingRuleVersion::query()->firstOrFail();
        $version->account_mappings = ['expense' => 'other'];
        $version->save();

        $this->assertSame('other', $version->fresh()->account_mappings['expense']);
    }

    #[Test]
    public function used_version_blocks_changes_to_immutable_fields(): void
    {
        $entity = $this->makeEntity();
        $version = PostingRuleVersion::query()->firstOrFail();
        $recId = $this->postedReconciliationId($entity);
        $split = new ReconciliationSplit;
        $split->fill([
            'reconciliation_id' => $recId,
            'purpose' => SplitPurpose::PostingRule,
            'amount_minor' => 100,
            'currency' => 'EUR',
            'posting_rule_version_id' => $version->getKey(),
        ]);
        $split->save();

        $version->account_mappings = ['expense' => 'altered'];

        $this->expectException(PostedRecordImmutableException::class);
        $version->save();
    }

    #[Test]
    public function used_version_allows_changes_to_non_immutable_fields(): void
    {
        $entity = $this->makeEntity();
        $version = PostingRuleVersion::query()->firstOrFail();
        $recId = $this->postedReconciliationId($entity);
        $split = new ReconciliationSplit;
        $split->fill([
            'reconciliation_id' => $recId,
            'purpose' => SplitPurpose::PostingRule,
            'amount_minor' => 100,
            'currency' => 'EUR',
            'posting_rule_version_id' => $version->getKey(),
        ]);
        $split->save();

        $before = $version->requires_receipt;
        $version->requires_receipt = ! $before;
        $version->save();

        $this->assertNotEquals($before, $version->fresh()->requires_receipt);
    }

    private function postedReconciliationId($entity): int
    {
        $account = $this->makeBankAccount($entity);
        $line = BankStatementLine::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'bank_account_id' => $account->getKey(),
            'external_id' => 'prv-'.uniqid(),
            'source' => 'synthetic',
            'amount_minor' => 100,
            'currency' => 'EUR',
            'booking_date' => '2026-09-11',
            'source_status' => StatementLineStatus::Booked->value,
        ]);
        $rec = Reconciliation::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'statement_line_id' => $line->getKey(),
            'status' => ReconciliationStatus::Posted,
            'version' => 1,
        ]);

        return (int) $rec->getKey();
    }
}