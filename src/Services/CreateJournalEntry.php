<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;

final class CreateJournalEntry
{
    public function __construct(
        private readonly LedgerEngine $ledger,
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
    ) {}

    public function handle(LegalEntity $entity, PostJournalCommand $command): JournalEntry
    {
        $this->authorizer->authorize('post_manual_journals', $entity);

        $actor = $this->actors->resolve();

        return $this->ledger->post(new PostJournalCommand(
            legalEntityId: (int) $entity->getKey(),
            postedOn: $command->postedOn,
            sourceType: $command->sourceType,
            sourceId: $command->sourceId,
            currency: $command->currency,
            baseCurrency: $command->baseCurrency,
            lines: $command->lines,
            description: $command->description,
            exchangeRate: $command->exchangeRate,
            postingRuleVersionId: $command->postingRuleVersionId,
            idempotencyKey: $command->idempotencyKey,
            postedByType: $actor?->getMorphClass() ?? $command->postedByType,
            postedById: $actor ? (string) $actor->getKey() : $command->postedById,
            reversesId: $command->reversesId,
        ));
    }
}
