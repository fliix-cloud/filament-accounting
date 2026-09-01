<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Exceptions\AuditEventImmutableException;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $sequence
 * @property int $event_schema_version
 * @property int $canonicalization_version
 * @property string $hash_algorithm
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $impersonator_type
 * @property string|null $impersonator_id
 * @property string $operation
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property string $canonical_payload
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $request_id
 * @property string|null $application_version
 * @property string|null $application_commit
 * @property string|null $configuration_snapshot_id
 * @property Carbon $occurred_at
 * @property Carbon|null $technical_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditEvent extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_audit_events';

    protected $fillable = [
        'legal_entity_id',
        'sequence',
        'event_schema_version',
        'canonicalization_version',
        'hash_algorithm',
        'actor_type',
        'actor_id',
        'impersonator_type',
        'impersonator_id',
        'operation',
        'target_type',
        'target_id',
        'reason',
        'payload',
        'canonical_payload',
        'previous_hash',
        'event_hash',
        'correlation_id',
        'causation_id',
        'request_id',
        'application_version',
        'application_commit',
        'configuration_snapshot_id',
        'occurred_at',
        'technical_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new AuditEventImmutableException('Audit events are append-only and cannot be updated.'));
        static::deleting(fn () => throw new AuditEventImmutableException('Audit events are append-only and cannot be deleted.'));
        static::replicating(fn () => throw new AuditEventImmutableException('Audit events cannot be replicated.'));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_schema_version' => 'integer',
            'canonicalization_version' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'technical_at' => 'datetime',
        ];
    }
}
