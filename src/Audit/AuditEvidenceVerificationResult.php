<?php

namespace FilamentAccounting\Audit;

final readonly class AuditEvidenceVerificationResult
{
    /**
     * @param  list<AuditChainIssue>  $issues
     */
    public function __construct(
        public string $evidenceHash,
        public AuditChainVerificationResult $chain,
        public AuditAnchorVerificationResult $anchors,
        public array $issues,
    ) {}

    public function isValid(): bool
    {
        return $this->issues === [] && $this->chain->isValid() && $this->anchors->isValid();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'valid' => $this->isValid(),
            'evidence_hash' => $this->evidenceHash,
            'issue_count' => count($this->issues) + count($this->chain->issues) + count($this->anchors->issues),
            'evidence' => [
                'issues' => array_map(fn (AuditChainIssue $issue): array => $issue->toArray(), $this->issues),
            ],
            'audit_chain' => [
                'event_count' => $this->chain->eventCount,
                'last_sequence' => $this->chain->lastSequence,
                'head_hash' => $this->chain->headHash,
                'issues' => array_map(fn (AuditChainIssue $issue): array => $issue->toArray(), $this->chain->issues),
            ],
            'external_anchors' => [
                'anchor_count' => $this->anchors->anchorCount,
                'last_anchored_sequence' => $this->anchors->lastAnchoredSequence,
                'latest_anchor_hash' => $this->anchors->latestAnchorHash,
                'issues' => array_map(fn (AuditChainIssue $issue): array => $issue->toArray(), $this->anchors->issues),
            ],
        ];
    }
}
