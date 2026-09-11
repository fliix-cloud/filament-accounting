<?php

namespace FilamentAccounting\Tests\Ledger;

use FilamentAccounting\Exceptions\PostedRecordImmutableException;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LedgerAccountProtectionTest extends TestCase
{
    #[Test]
    public function unused_account_can_be_renamed(): void
    {
        $entity = $this->makeEntity();
        $account = LedgerAccount::query()->where('legal_entity_id', $entity->getKey())
            ->whereDoesntHave('roleAssignments')
            ->whereDoesntHave('journalLines')
            ->firstOrFail();

        $account->name = 'Renamed Account';
        $account->save();
        $this->assertSame('Renamed Account', $account->fresh()->name);
    }

    #[Test]
    public function system_role_account_cannot_be_renamed(): void
    {
        $entity = $this->makeEntity();
        // Find an account with role assignments (chart seeds create these)
        $account = LedgerAccount::query()->where('legal_entity_id', $entity->getKey())
            ->whereHas('roleAssignments')
            ->firstOrFail();

        $account->name = 'Hacked Name';
        $this->expectException(PostedRecordImmutableException::class);
        $account->save();
    }

    #[Test]
    public function non_role_account_without_journal_entries_can_be_recoded(): void
    {
        $entity = $this->makeEntity();
        $account = LedgerAccount::query()->where('legal_entity_id', $entity->getKey())
            ->whereDoesntHave('roleAssignments')
            ->whereDoesntHave('journalLines')
            ->firstOrFail();

        $account->code = '9999';
        $account->save();
        $this->assertSame('9999', $account->fresh()->code);
    }
}