<?php

namespace FilamentAccounting\Audit;

use FilamentAccounting\Models\AuditEvent;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Throwable;

final class AuditChainVerifier
{
    public function __construct(
        private readonly CanonicalJson $canonicalJson,
        private readonly AuditEventHasher $hasher,
    ) {}

    public function verify(int $legalEntityId): AuditChainVerificationResult
    {
        $events = AuditEvent::query()
            ->where('legal_entity_id', $legalEntityId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $issues = [];
        $expectedSequence = 1;
        $previousHash = null;

        foreach ($events as $event) {
            $sequence = (int) $event->sequence;

            if ($sequence !== $expectedSequence) {
                $issues[] = new AuditChainIssue(
                    'sequence_mismatch',
                    "Expected audit sequence {$expectedSequence}, found {$sequence}.",
                    $sequence,
                );
            }

            if ((int) $event->event_schema_version !== AuditEventHasher::EVENT_SCHEMA_VERSION) {
                $issues[] = new AuditChainIssue('event_schema_version_unsupported', 'Unsupported audit event schema version.', $sequence);
            }

            if ((int) $event->canonicalization_version !== CanonicalJson::VERSION) {
                $issues[] = new AuditChainIssue('canonicalization_version_unsupported', 'Unsupported canonicalization version.', $sequence);
            }

            if ((string) $event->hash_algorithm !== AuditEventHasher::HASH_ALGORITHM) {
                $issues[] = new AuditChainIssue('hash_algorithm_unsupported', 'Unsupported audit hash algorithm.', $sequence);
            }

            try {
                $canonicalPayload = $this->canonicalPayloadFrom($event);

                if (! hash_equals($canonicalPayload, (string) $event->canonical_payload)) {
                    $issues[] = new AuditChainIssue('canonical_payload_mismatch', 'Stored payload does not match its canonical representation.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('payload_invalid', $exception->getMessage(), $sequence);
            }

            if ($event->previous_hash !== $previousHash) {
                $issues[] = new AuditChainIssue('previous_hash_mismatch', 'Audit event does not reference the preceding event hash.', $sequence);
            }

            try {
                $expectedHash = $this->hasher->hash($event->getAttributes());

                if (! hash_equals($expectedHash, (string) $event->event_hash)) {
                    $issues[] = new AuditChainIssue('event_hash_mismatch', 'Audit event hash is invalid.', $sequence);
                }
            } catch (Throwable $exception) {
                $issues[] = new AuditChainIssue('event_hash_invalid', $exception->getMessage(), $sequence);
            }

            $technicalTimestamp = $event->technical_at?->format('Y-m-d H:i:s');

            if ($event->created_at?->format('Y-m-d H:i:s') !== $technicalTimestamp
                || $event->updated_at?->format('Y-m-d H:i:s') !== $technicalTimestamp) {
                $issues[] = new AuditChainIssue('technical_timestamp_mismatch', 'Framework timestamps differ from the immutable technical timestamp.', $sequence);
            }

            $previousHash = (string) $event->event_hash;
            $expectedSequence++;
        }

        $this->verifyHead(
            $events->count(),
            $events->isEmpty() ? 0 : (int) $events->last()->sequence,
            $previousHash,
            $legalEntityId,
            (new AuditEvent)->getConnection(),
            $issues,
        );

        return new AuditChainVerificationResult($events->count(), $previousHash, $issues);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function payload(AuditEvent $event): array
    {
        $rawPayload = $event->getRawOriginal('payload');

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

    private function canonicalPayloadFrom(AuditEvent $event): string
    {
        return $this->canonicalJson->encode($this->payload($event));
    }

    /**
     * @param  list<AuditChainIssue>  $issues
     */
    private function verifyHead(
        int $eventCount,
        int $lastSequence,
        ?string $lastHash,
        int $legalEntityId,
        ConnectionInterface $connection,
        array &$issues,
    ): void {
        $head = $connection->table('accounting_audit_chain_heads')
            ->where('legal_entity_id', $legalEntityId)
            ->first();

        if ($head === null && $eventCount === 0) {
            return;
        }

        if ($head === null) {
            $issues[] = new AuditChainIssue('chain_head_missing', 'Audit chain head is missing.');

            return;
        }

        if ((int) $head->event_count !== $eventCount
            || (int) $head->last_sequence !== $lastSequence
            || $head->last_event_hash !== $lastHash) {
            $issues[] = new AuditChainIssue('chain_head_mismatch', 'Audit chain head does not match the stored event chain.');
        }
    }
}
