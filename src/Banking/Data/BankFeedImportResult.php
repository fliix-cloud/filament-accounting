<?php

namespace FilamentAccounting\Banking\Data;

final readonly class BankFeedImportResult
{
    public function __construct(
        public int $upserted,
        public int $skipped,
        public ?string $cursor = null,
    ) {}
}
