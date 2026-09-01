<?php

namespace FilamentAccounting\Audit;

use InvalidArgumentException;

final readonly class AuditAnchor
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public int $schemaVersion,
        public int $canonicalizationVersion,
        public string $hashAlgorithm,
        public int $legalEntityId,
        public string $legalEntityUuid,
        public int $lastSequence,
        public int $eventCount,
        public string $lastEventHash,
        public ?string $previousAnchorHash,
        public string $anchoredAt,
        public string $anchorHash,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'canonicalization_version' => $this->canonicalizationVersion,
            'hash_algorithm' => $this->hashAlgorithm,
            'legal_entity_id' => $this->legalEntityId,
            'legal_entity_uuid' => $this->legalEntityUuid,
            'last_sequence' => $this->lastSequence,
            'event_count' => $this->eventCount,
            'last_event_hash' => $this->lastEventHash,
            'previous_anchor_hash' => $this->previousAnchorHash,
            'anchored_at' => $this->anchoredAt,
            'anchor_hash' => $this->anchorHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = [
            'schema_version',
            'canonicalization_version',
            'hash_algorithm',
            'legal_entity_id',
            'legal_entity_uuid',
            'last_sequence',
            'event_count',
            'last_event_hash',
            'previous_anchor_hash',
            'anchored_at',
            'anchor_hash',
        ];

        $actualFields = array_keys($data);
        sort($fields);
        sort($actualFields);

        if ($actualFields !== $fields) {
            throw new InvalidArgumentException('Audit anchor contains missing or unsupported fields.');
        }

        foreach ($fields as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Audit anchor field [{$key}] is missing.");
            }
        }

        if (! is_int($data['schema_version'])
            || ! is_int($data['canonicalization_version'])
            || ! is_int($data['legal_entity_id'])
            || ! is_int($data['last_sequence'])
            || ! is_int($data['event_count'])) {
            throw new InvalidArgumentException('Audit anchor integer fields must be encoded as integers.');
        }

        foreach ([
            'hash_algorithm',
            'legal_entity_uuid',
            'last_event_hash',
            'anchored_at',
            'anchor_hash',
        ] as $key) {
            if (! is_string($data[$key]) || $data[$key] === '') {
                throw new InvalidArgumentException("Audit anchor field [{$key}] must be a non-empty string.");
            }
        }

        $previousAnchorHash = $data['previous_anchor_hash'] ?? null;

        if ($previousAnchorHash !== null && (! is_string($previousAnchorHash) || $previousAnchorHash === '')) {
            throw new InvalidArgumentException('Audit anchor field [previous_anchor_hash] must be null or a non-empty string.');
        }

        foreach (['last_event_hash', 'anchor_hash'] as $key) {
            if (! preg_match('/\A[0-9a-f]{64}\z/', $data[$key])) {
                throw new InvalidArgumentException("Audit anchor field [{$key}] must be a lowercase SHA-256 hash.");
            }
        }

        if ($previousAnchorHash !== null && ! preg_match('/\A[0-9a-f]{64}\z/', $previousAnchorHash)) {
            throw new InvalidArgumentException('Audit anchor field [previous_anchor_hash] must be a lowercase SHA-256 hash.');
        }

        return new self(
            $data['schema_version'],
            $data['canonicalization_version'],
            $data['hash_algorithm'],
            $data['legal_entity_id'],
            $data['legal_entity_uuid'],
            $data['last_sequence'],
            $data['event_count'],
            $data['last_event_hash'],
            $previousAnchorHash,
            $data['anchored_at'],
            $data['anchor_hash'],
        );
    }
}
