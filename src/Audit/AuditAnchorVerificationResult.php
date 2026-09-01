<?php

namespace FilamentAccounting\Audit;

final readonly class AuditAnchorVerificationResult
{
    /**
     * @param  list<AuditChainIssue>  $issues
     */
    public function __construct(
        public int $anchorCount,
        public ?int $lastAnchoredSequence,
        public ?string $latestAnchorHash,
        public array $issues,
    ) {}

    public function isValid(): bool
    {
        return $this->issues === [];
    }
}
