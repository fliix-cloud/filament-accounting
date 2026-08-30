<?php

namespace FilamentAccounting\Ledger;

final readonly class PostJournalCommand
{
    /**
     * @param  list<JournalLineDraft>  $lines
     */
    public function __construct(
        public int $legalEntityId,
        public string $postedOn,
        public string $sourceType,
        public ?string $sourceId,
        public string $currency,
        public string $baseCurrency,
        public array $lines,
        public ?string $description = null,
        public ?string $exchangeRate = null,
        public ?int $postingRuleVersionId = null,
        public ?string $idempotencyKey = null,
        public ?string $postedByType = null,
        public ?string $postedById = null,
        public ?int $reversesId = null,
    ) {}
}
