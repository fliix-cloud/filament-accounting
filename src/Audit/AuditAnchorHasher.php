<?php

namespace FilamentAccounting\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class AuditAnchorHasher
{
    public const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly CanonicalJson $canonicalJson,
    ) {}

    public function create(
        int $legalEntityId,
        string $legalEntityUuid,
        AuditChainVerificationResult $chain,
        ?AuditAnchor $previous = null,
        ?DateTimeInterface $anchoredAt = null,
    ): AuditAnchor {
        if (! $chain->isValid() || $chain->eventCount === 0 || $chain->headHash === null) {
            throw new InvalidArgumentException('Only a valid, non-empty audit chain can be anchored.');
        }

        $attributes = [
            'schema_version' => AuditAnchor::SCHEMA_VERSION,
            'canonicalization_version' => CanonicalJson::VERSION,
            'hash_algorithm' => self::HASH_ALGORITHM,
            'legal_entity_id' => $legalEntityId,
            'legal_entity_uuid' => $legalEntityUuid,
            'last_sequence' => $chain->lastSequence,
            'event_count' => $chain->eventCount,
            'last_event_hash' => $chain->headHash,
            'previous_anchor_hash' => $previous?->anchorHash,
            'anchored_at' => $this->timestamp($anchoredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        ];

        return AuditAnchor::fromArray([
            ...$attributes,
            'anchor_hash' => $this->hash($attributes),
        ]);
    }

    /**
     * @param  AuditAnchor|array<string, mixed>  $anchor
     */
    public function hash(AuditAnchor|array $anchor): string
    {
        $attributes = $anchor instanceof AuditAnchor ? $anchor->toArray() : $anchor;
        $algorithm = (string) ($attributes['hash_algorithm'] ?? self::HASH_ALGORITHM);

        if ($algorithm !== self::HASH_ALGORITHM) {
            throw new InvalidArgumentException("Unsupported audit anchor hash algorithm [{$algorithm}].");
        }

        unset($attributes['anchor_hash']);

        return hash($algorithm, $this->canonicalJson->encode($attributes));
    }

    private function timestamp(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
