<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\LegalEntity;
use Throwable;

final class AuditAnchorVerifier
{
    public function __construct(
        private readonly AuditAnchorStore $store,
        private readonly AuditAnchorChainValidator $validator,
    ) {}

    public function verify(
        LegalEntity $legalEntity,
        AuditChainVerificationResult $chain,
    ): AuditAnchorVerificationResult {
        try {
            $anchors = $this->store->all((string) $legalEntity->uuid);
        } catch (Throwable $exception) {
            return new AuditAnchorVerificationResult(0, null, null, [
                new AuditChainIssue('external_anchor_unreadable', $exception->getMessage()),
            ]);
        }

        $eventHashes = AuditEvent::query()
            ->where('legal_entity_id', $legalEntity->getKey())
            ->whereIn('sequence', array_values(array_unique(array_map(
                fn (AuditAnchor $anchor): int => $anchor->lastSequence,
                $anchors,
            ))))
            ->pluck('event_hash', 'sequence');

        return $this->validator->verify(
            (int) $legalEntity->getKey(),
            (string) $legalEntity->uuid,
            $chain,
            $anchors,
            $eventHashes->all(),
            (bool) config('filament-accounting.audit.anchor.required', false),
            (bool) config('filament-accounting.audit.anchor.immutable_storage_attested', false),
        );
    }
}
