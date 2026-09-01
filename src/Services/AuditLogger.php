<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Audit\AuditEventHasher;
use FilamentAccounting\Audit\CanonicalJson;
use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Exceptions\AuditChainCompromisedException;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly AccountingActorResolver $actors,
        private readonly AuditEventHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        LegalEntity $entity,
        string $operation,
        ?Model $target = null,
        array $payload = [],
        ?string $reason = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): AuditEvent {
        $actor = $this->actors->resolve();
        $connection = $entity->getConnection();

        return $connection->transaction(function () use ($actor, $causationId, $connection, $correlationId, $entity, $operation, $payload, $reason, $target): AuditEvent {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();

            $head = $connection->table('accounting_audit_chain_heads')
                ->where('legal_entity_id', $entity->getKey())
                ->lockForUpdate()
                ->first();
            $latest = AuditEvent::query()
                ->where('legal_entity_id', $entity->getKey())
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            if (($head === null) !== ($latest === null)
                || ($head !== null && ((int) $head->last_sequence !== (int) $latest?->sequence
                    || $head->last_event_hash !== $latest?->event_hash))) {
                throw new AuditChainCompromisedException('The audit chain head does not match the latest event; refusing to append.');
            }

            $technicalAt = now()->utc()->startOfSecond();
            $redactedPayload = $this->redact($payload);
            $request = app()->bound('request') ? request() : null;
            $sequence = $head === null ? 1 : ((int) $head->last_sequence + 1);
            $event = new AuditEvent;
            $event->uuid = (string) Str::uuid();
            $event->fill([
                'legal_entity_id' => $entity->getKey(),
                'sequence' => $sequence,
                'event_schema_version' => AuditEventHasher::EVENT_SCHEMA_VERSION,
                'canonicalization_version' => CanonicalJson::VERSION,
                'hash_algorithm' => AuditEventHasher::HASH_ALGORITHM,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor ? (string) $actor->getKey() : null,
                'impersonator_type' => $request?->attributes->get('accounting_impersonator_type'),
                'impersonator_id' => $request?->attributes->get('accounting_impersonator_id'),
                'operation' => $operation,
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target ? (string) $target->getKey() : null,
                'reason' => $reason,
                'payload' => $redactedPayload,
                'canonical_payload' => $this->hasher->canonicalPayload($redactedPayload),
                'previous_hash' => $head?->last_event_hash,
                'correlation_id' => $correlationId ?: $request?->headers->get('X-Correlation-Id'),
                'causation_id' => $causationId ?: $request?->headers->get('X-Causation-Id'),
                'request_id' => $request?->headers->get('X-Request-Id') ?: (string) Str::uuid(),
                'application_version' => $this->configString('application_version'),
                'application_commit' => $this->configString('application_commit'),
                'configuration_snapshot_id' => $this->configString('configuration_snapshot_id'),
                'occurred_at' => $technicalAt,
                'technical_at' => $technicalAt,
            ]);
            $event->setCreatedAt($technicalAt);
            $event->setUpdatedAt($technicalAt);
            $event->event_hash = $this->hasher->hash($event->getAttributes());
            $event->save();

            $connection->table('accounting_audit_chain_heads')->updateOrInsert(
                ['legal_entity_id' => $entity->getKey()],
                [
                    'last_sequence' => $sequence,
                    'last_event_hash' => $event->event_hash,
                    'event_count' => $sequence,
                    'updated_at' => $technicalAt,
                ],
            );

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $blocked = ['pin', 'tan', 'password', 'secret', 'token', 'iban_full'];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    private function configString(string $key): ?string
    {
        $value = config("filament-accounting.audit.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
