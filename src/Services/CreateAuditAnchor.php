<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\AuditAnchor;
use FilamentAccounting\Audit\AuditAnchorHasher;
use FilamentAccounting\Audit\AuditAnchorVerifier;
use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Exceptions\AuditAnchorException;
use FilamentAccounting\Models\LegalEntity;

final class CreateAuditAnchor
{
    public function __construct(
        private readonly AuditChainVerifier $chainVerifier,
        private readonly AuditAnchorVerifier $anchorVerifier,
        private readonly AuditAnchorHasher $hasher,
        private readonly AuditAnchorStore $store,
    ) {}

    public function handle(LegalEntity $legalEntity): AuditAnchor
    {
        $chain = $this->chainVerifier->verify((int) $legalEntity->getKey());

        if (! $chain->isValid()) {
            $codes = implode(', ', array_map(fn ($issue): string => $issue->code, $chain->issues));

            throw new AuditAnchorException("Cannot anchor a compromised audit chain: {$codes}.");
        }

        if ($chain->eventCount === 0 || $chain->headHash === null) {
            throw new AuditAnchorException('Cannot anchor an empty audit chain.');
        }

        $existing = $this->store->all((string) $legalEntity->uuid);

        if ($existing !== []) {
            $verification = $this->anchorVerifier->verify($legalEntity, $chain);

            if (! $verification->isValid()) {
                $codes = implode(', ', array_map(fn ($issue): string => $issue->code, $verification->issues));

                throw new AuditAnchorException("Cannot extend compromised external audit anchors: {$codes}.");
            }
        }

        $previous = $existing === [] ? null : $existing[array_key_last($existing)];

        if ($previous instanceof AuditAnchor && $previous->lastSequence === $chain->lastSequence) {
            if (! hash_equals($previous->lastEventHash, $chain->headHash)) {
                throw new AuditAnchorException('Latest external audit anchor conflicts with the current audit-chain head.');
            }

            return $previous;
        }

        if ($previous instanceof AuditAnchor && $previous->lastSequence > $chain->lastSequence) {
            throw new AuditAnchorException('Latest external audit anchor is ahead of the current audit chain.');
        }

        $anchor = $this->hasher->create(
            (int) $legalEntity->getKey(),
            (string) $legalEntity->uuid,
            $chain,
            $previous,
        );

        $this->store->putOnce($anchor);

        $verification = $this->anchorVerifier->verify($legalEntity, $chain);

        if (! $verification->isValid()) {
            $codes = implode(', ', array_map(fn ($issue): string => $issue->code, $verification->issues));

            throw new AuditAnchorException("External audit anchor failed verification after writing: {$codes}.");
        }

        return $anchor;
    }
}
