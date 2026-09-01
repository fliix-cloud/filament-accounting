<?php

namespace FilamentAccounting\Audit;

use Throwable;

final class AuditAnchorChainValidator
{
    public function __construct(
        private readonly AuditAnchorHasher $hasher,
    ) {}

    /**
     * @param  list<AuditAnchor>  $anchors
     * @param  array<int|string, string>  $eventHashes
     */
    public function verify(
        int $legalEntityId,
        string $legalEntityUuid,
        AuditChainVerificationResult $chain,
        array $anchors,
        array $eventHashes,
        bool $required,
        bool $immutableStorageAttested,
    ): AuditAnchorVerificationResult {
        if ($anchors === []) {
            $issues = $required
                ? [new AuditChainIssue('external_anchor_missing', 'No external audit anchor exists for this legal entity.')]
                : [];

            return new AuditAnchorVerificationResult(0, null, null, $issues);
        }

        $issues = [];

        if (! $immutableStorageAttested) {
            $issues[] = new AuditChainIssue(
                'external_anchor_storage_unattested',
                'The host has not attested immutable/versioned storage for external audit anchors.',
            );
        }

        $previous = null;

        foreach ($anchors as $anchor) {
            $sequence = $anchor->lastSequence;

            if ($anchor->schemaVersion !== AuditAnchor::SCHEMA_VERSION) {
                $issues[] = new AuditChainIssue('external_anchor_schema_unsupported', 'Unsupported external anchor schema version.', $sequence);
            }

            if ($anchor->canonicalizationVersion !== CanonicalJson::VERSION) {
                $issues[] = new AuditChainIssue('external_anchor_canonicalization_unsupported', 'Unsupported external anchor canonicalization version.', $sequence);
            }

            if ($anchor->hashAlgorithm !== AuditAnchorHasher::HASH_ALGORITHM) {
                $issues[] = new AuditChainIssue('external_anchor_hash_algorithm_unsupported', 'Unsupported external anchor hash algorithm.', $sequence);
            }

            try {
                $expectedHash = $this->hasher->hash($anchor);

                if (! hash_equals($expectedHash, $anchor->anchorHash)) {
                    $issues[] = new AuditChainIssue('external_anchor_hash_mismatch', 'External audit anchor hash is invalid.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('external_anchor_hash_invalid', $exception->getMessage(), $sequence);
            }

            if ($anchor->legalEntityId !== $legalEntityId
                || $anchor->legalEntityUuid !== $legalEntityUuid) {
                $issues[] = new AuditChainIssue('external_anchor_entity_mismatch', 'External audit anchor belongs to a different legal entity.', $sequence);
            }

            if ($sequence < 1 || $anchor->eventCount !== $sequence) {
                $issues[] = new AuditChainIssue('external_anchor_count_mismatch', 'External audit anchor sequence and event count are inconsistent.', $sequence);
            }

            if ($previous === null) {
                if ($anchor->previousAnchorHash !== null) {
                    $issues[] = new AuditChainIssue('external_anchor_predecessor_mismatch', 'First available external anchor unexpectedly references a predecessor.', $sequence);
                }
            } elseif ($sequence <= $previous->lastSequence
                || $anchor->previousAnchorHash !== $previous->anchorHash) {
                $issues[] = new AuditChainIssue('external_anchor_predecessor_mismatch', 'External audit anchor does not extend the preceding anchor.', $sequence);
            }

            $eventHash = $eventHashes[$sequence] ?? null;

            if (! is_string($eventHash)) {
                $issues[] = new AuditChainIssue('external_anchor_event_missing', 'The audit event referenced by the external anchor is missing.', $sequence);
            } elseif (! hash_equals($eventHash, $anchor->lastEventHash)) {
                $issues[] = new AuditChainIssue('external_anchor_event_mismatch', 'External audit anchor does not match the referenced audit event.', $sequence);
            }

            if ($sequence > $chain->lastSequence) {
                $issues[] = new AuditChainIssue('external_anchor_ahead_of_chain', 'External audit anchor is ahead of the stored audit chain.', $sequence);
            }

            $previous = $anchor;
        }

        return new AuditAnchorVerificationResult(
            count($anchors),
            $previous?->lastSequence,
            $previous?->anchorHash,
            $issues,
        );
    }
}
