<?php

namespace FilamentAccounting\Reconciliation\Data;

final readonly class MatchSuggestion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $targetType,
        public int $targetId,
        public int $score,
        public array $reasons,
        public bool $ambiguous = false,
    ) {}
}
