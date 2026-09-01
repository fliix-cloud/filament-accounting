<?php

namespace FilamentAccounting\Audit;

final readonly class AuditChainVerificationResult
{
    /**
     * @param  list<AuditChainIssue>  $issues
     */
    public function __construct(
        public int $eventCount,
        public int $lastSequence,
        public ?string $headHash,
        public array $issues,
    ) {}

    public function isValid(): bool
    {
        return $this->issues === [];
    }
}
