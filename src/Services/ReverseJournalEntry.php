<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Ledger\ReverseJournalCommand;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Ownership\LegalEntityScope;

final class ReverseJournalEntry
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
    ) {}

    public function handle(JournalEntry $entry, string $postedOn, ?string $reason = null, ?string $idempotencyKey = null): JournalEntry
    {
        $this->scope->assertSame((int) $entry->legal_entity_id);
        $this->authorizer->authorize('post_manual_journals', $entry);

        $actor = $this->actors->resolve();

        return $this->ledger->reverse(new ReverseJournalCommand(
            journalEntryId: (int) $entry->getKey(),
            postedOn: $postedOn,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
            postedByType: $actor?->getMorphClass(),
            postedById: $actor ? (string) $actor->getKey() : null,
        ));
    }
}
