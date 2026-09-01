<?php

namespace FilamentAccounting\Audit;

use DateTimeImmutable;
use DateTimeZone;
use FilamentAccounting\Contracts\AuditAnchorStore;
use FilamentAccounting\Exceptions\AuditEvidenceException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\LegalEntity;

final class AuditEvidenceExporter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly AuditChainVerifier $chainVerifier,
        private readonly AuditAnchorVerifier $anchorVerifier,
        private readonly AuditAnchorStore $anchorStore,
        private readonly CanonicalJson $canonicalJson,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(LegalEntity $legalEntity): array
    {
        $chain = $this->chainVerifier->verify((int) $legalEntity->getKey());

        if (! $chain->isValid()) {
            throw new AuditEvidenceException('Cannot export a compromised audit chain: '.$this->codes($chain->issues).'.');
        }

        $anchorVerification = $this->anchorVerifier->verify($legalEntity, $chain);

        if (! $anchorVerification->isValid()) {
            throw new AuditEvidenceException('Cannot export compromised external anchors: '.$this->codes($anchorVerification->issues).'.');
        }

        $events = AuditEvent::query()
            ->where('legal_entity_id', $legalEntity->getKey())
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(function (AuditEvent $event): array {
                $attributes = $event->getAttributes();
                unset($attributes['id']);

                return $attributes;
            })
            ->all();

        $anchors = array_map(
            fn (AuditAnchor $anchor): array => $anchor->toArray(),
            $this->anchorStore->all((string) $legalEntity->uuid),
        );

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'canonicalization_version' => CanonicalJson::VERSION,
            'hash_algorithm' => AuditEventHasher::HASH_ALGORITHM,
            'exported_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'legal_entity' => [
                'id' => (int) $legalEntity->getKey(),
                'uuid' => (string) $legalEntity->uuid,
            ],
            'anchor_policy' => [
                'required' => (bool) config('filament-accounting.audit.anchor.required', false),
                'immutable_storage_attested' => (bool) config('filament-accounting.audit.anchor.immutable_storage_attested', false),
            ],
            'audit_chain' => [
                'head' => $chain->eventCount === 0 ? null : [
                    'legal_entity_id' => (int) $legalEntity->getKey(),
                    'event_count' => $chain->eventCount,
                    'last_sequence' => $chain->lastSequence,
                    'last_event_hash' => $chain->headHash,
                ],
                'events' => $events,
            ],
            'external_anchors' => $anchors,
        ];

        return [
            ...$evidence,
            'evidence_hash' => hash(
                AuditEventHasher::HASH_ALGORITHM,
                $this->canonicalJson->encode($evidence),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function encode(array $evidence): string
    {
        return $this->canonicalJson->encode($evidence);
    }

    /**
     * @param  list<AuditChainIssue>  $issues
     */
    private function codes(array $issues): string
    {
        return implode(', ', array_map(fn (AuditChainIssue $issue): string => $issue->code, $issues));
    }
}
