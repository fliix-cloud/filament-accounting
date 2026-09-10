<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Models\AuditEvent;

final class AuditChainVerifier
{
    public function __construct(
        private readonly AuditEventChainValidator $validator,
    ) {}

    public function verify(int $legalEntityId): AuditChainVerificationResult
    {
        $events = AuditEvent::query()
            ->where('legal_entity_id', $legalEntityId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->lazy(1);

        $head = (new AuditEvent)->getConnection()
            ->table('accounting_audit_chain_heads')
            ->where('legal_entity_id', $legalEntityId)
            ->first();

        return $this->validator->verify(
            $legalEntityId,
            $events->map(fn (AuditEvent $event): array => $event->getAttributes()),
            $head === null ? null : (array) $head,
        );
    }
}
