<?php

namespace FilamentAccounting\Audit;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class AuditEventChainValidator
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly AuditEventHasher $hasher,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>|null  $head
     */
    public function verify(int $legalEntityId, array $events, ?array $head): AuditChainVerificationResult
    {
        $issues = [];
        $expectedSequence = 1;
        $previousHash = null;
        $lastSequence = 0;

        foreach ($events as $event) {
            $sequence = (int) ($event['sequence'] ?? 0);
            $lastSequence = $sequence;

            if ((int) ($event['legal_entity_id'] ?? 0) !== $legalEntityId) {
                $issues[] = new AuditChainIssue('event_entity_mismatch', 'Audit event belongs to a different legal entity.', $sequence);
            }

            if ($sequence !== $expectedSequence) {
                $issues[] = new AuditChainIssue(
                    'sequence_mismatch',
                    "Expected audit sequence {$expectedSequence}, found {$sequence}.",
                    $sequence,
                );
            }

            if ((int) ($event['event_schema_version'] ?? 0) !== AuditEventHasher::EVENT_SCHEMA_VERSION) {
                $issues[] = new AuditChainIssue('event_schema_version_unsupported', 'Unsupported audit event schema version.', $sequence);
            }

            if ((int) ($event['canonicalization_version'] ?? 0) !== CanonicalJson::VERSION) {
                $issues[] = new AuditChainIssue('canonicalization_version_unsupported', 'Unsupported canonicalization version.', $sequence);
            }

            if ((string) ($event['hash_algorithm'] ?? '') !== AuditEventHasher::HASH_ALGORITHM) {
                $issues[] = new AuditChainIssue('hash_algorithm_unsupported', 'Unsupported audit hash algorithm.', $sequence);
            }

            try {
                $canonicalPayload = $this->canonicalJson->encode($this->payload($event['payload'] ?? null));

                if (! hash_equals($canonicalPayload, (string) ($event['canonical_payload'] ?? ''))) {
                    $issues[] = new AuditChainIssue('canonical_payload_mismatch', 'Stored payload does not match its canonical representation.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('payload_invalid', $exception->getMessage(), $sequence);
            }

            if (($event['previous_hash'] ?? null) !== $previousHash) {
                $issues[] = new AuditChainIssue('previous_hash_mismatch', 'Audit event does not reference the preceding event hash.', $sequence);
            }

            try {
                $expectedHash = $this->hasher->hash($event);

                if (! hash_equals($expectedHash, (string) ($event['event_hash'] ?? ''))) {
                    $issues[] = new AuditChainIssue('event_hash_mismatch', 'Audit event hash is invalid.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('event_hash_invalid', $exception->getMessage(), $sequence);
            }

            try {
                $technicalTimestamp = $this->timestamp($event['technical_at'] ?? null);

                if ($this->timestamp($event['created_at'] ?? null) !== $technicalTimestamp
                    || $this->timestamp($event['updated_at'] ?? null) !== $technicalTimestamp) {
                    $issues[] = new AuditChainIssue('technical_timestamp_mismatch', 'Framework timestamps differ from the immutable technical timestamp.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('technical_timestamp_invalid', $exception->getMessage(), $sequence);
            }

            $previousHash = (string) ($event['event_hash'] ?? '');
            $expectedSequence++;
        }

        $this->verifyHead(count($events), $lastSequence, $previousHash, $head, $issues);

        return new AuditChainVerificationResult(
            count($events),
            $lastSequence,
            $previousHash,
            $issues,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function payload(mixed $rawPayload): array
    {
        if ($rawPayload === null || $rawPayload === '') {
            return [];
        }

        $payload = is_string($rawPayload)
            ? json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR)
            : $rawPayload;

        if (! is_array($payload)) {
            throw new JsonException('Audit payload must decode to an object or array.');
        }

        return $payload;
    }

    private function timestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new JsonException('Audit timestamps must not be empty.');
        }

        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>|null  $head
     * @param  list<AuditChainIssue>  $issues
     */
    private function verifyHead(
        int $eventCount,
        int $lastSequence,
        ?string $lastHash,
        ?array $head,
        array &$issues,
    ): void {
        if ($head === null && $eventCount === 0) {
            return;
        }

        if ($head === null) {
            $issues[] = new AuditChainIssue('chain_head_missing', 'Audit chain head is missing.');

            return;
        }

        if ((int) ($head['event_count'] ?? 0) !== $eventCount
            || (int) ($head['last_sequence'] ?? 0) !== $lastSequence
            || ($head['last_event_hash'] ?? null) !== $lastHash) {
            $issues[] = new AuditChainIssue('chain_head_mismatch', 'Audit chain head does not match the stored event chain.');
        }
    }
}
