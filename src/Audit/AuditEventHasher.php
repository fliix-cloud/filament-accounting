<?php

namespace FilamentAccounting\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class AuditEventHasher
{
    public const EVENT_SCHEMA_VERSION = 1;

    public const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly CanonicalJson $canonicalJson,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalPayload(array $payload): string
    {
        return $this->canonicalJson->encode($payload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function hash(array $attributes): string
    {
        $algorithm = (string) ($attributes['hash_algorithm'] ?? self::HASH_ALGORITHM);

        if ($algorithm !== self::HASH_ALGORITHM) {
            throw new InvalidArgumentException("Unsupported audit hash algorithm [{$algorithm}].");
        }

        return hash($algorithm, $this->canonicalJson->encode([
            'actor_id' => $this->nullableString($attributes['actor_id'] ?? null),
            'actor_type' => $this->nullableString($attributes['actor_type'] ?? null),
            'application_commit' => $this->nullableString($attributes['application_commit'] ?? null),
            'application_version' => $this->nullableString($attributes['application_version'] ?? null),
            'canonical_payload' => (string) ($attributes['canonical_payload'] ?? ''),
            'canonicalization_version' => (int) ($attributes['canonicalization_version'] ?? CanonicalJson::VERSION),
            'causation_id' => $this->nullableString($attributes['causation_id'] ?? null),
            'configuration_snapshot_id' => $this->nullableString($attributes['configuration_snapshot_id'] ?? null),
            'correlation_id' => $this->nullableString($attributes['correlation_id'] ?? null),
            'event_schema_version' => (int) ($attributes['event_schema_version'] ?? self::EVENT_SCHEMA_VERSION),
            'hash_algorithm' => $algorithm,
            'impersonator_id' => $this->nullableString($attributes['impersonator_id'] ?? null),
            'impersonator_type' => $this->nullableString($attributes['impersonator_type'] ?? null),
            'legal_entity_id' => (int) ($attributes['legal_entity_id'] ?? 0),
            'occurred_at' => $this->timestamp($attributes['occurred_at'] ?? null),
            'operation' => (string) ($attributes['operation'] ?? ''),
            'previous_hash' => $this->nullableString($attributes['previous_hash'] ?? null),
            'reason' => $this->nullableString($attributes['reason'] ?? null),
            'request_id' => $this->nullableString($attributes['request_id'] ?? null),
            'sequence' => (int) ($attributes['sequence'] ?? 0),
            'target_id' => $this->nullableString($attributes['target_id'] ?? null),
            'target_type' => $this->nullableString($attributes['target_type'] ?? null),
            'technical_at' => $this->timestamp($attributes['technical_at'] ?? null),
            'uuid' => (string) ($attributes['uuid'] ?? ''),
        ]));
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function timestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Audit event timestamps must not be empty.');
        }

        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
