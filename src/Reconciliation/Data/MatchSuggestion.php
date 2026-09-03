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

    public function confidence(): string
    {
        $strongSignals = array_intersect($this->reasons, [
            'end_to_end',
            'document_number',
            'amount',
            'iban',
            'learned_rule',
        ]);

        if ($this->score >= 160 && count($strongSignals) >= 2 && ! $this->ambiguous) {
            return 'high';
        }

        if ($this->score >= 90 && count($strongSignals) >= 1 && ! $this->ambiguous) {
            return 'medium';
        }

        return 'low';
    }
}
