<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Models\AuditEvent;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function __construct(
        private readonly AccountingActorResolver $actors,
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
    ): AuditEvent {
        $actor = $this->actors->resolve();

        $event = new AuditEvent;
        $event->fill([
            'legal_entity_id' => $entity->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor ? (string) $actor->getKey() : null,
            'operation' => $operation,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target ? (string) $target->getKey() : null,
            'reason' => $reason,
            'payload' => $this->redact($payload),
            'correlation_id' => $correlationId,
            'request_id' => request()?->headers->get('X-Request-Id') ?: (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
        $event->save();

        return $event;
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
            }
        }

        return $payload;
    }
}
