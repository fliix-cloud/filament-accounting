<?php

namespace FilamentAccounting\Ledger;

final readonly class ReverseJournalCommand
{
    public function __construct(
        public int $journalEntryId,
        public string $postedOn,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public ?string $postedByType = null,
        public ?string $postedById = null,
    ) {}
}
