<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $operation
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property string|null $correlation_id
 * @property string|null $request_id
 * @property Carbon $occurred_at
 */
class AuditEvent extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_audit_events';

    protected $fillable = [
        'legal_entity_id',
        'actor_type',
        'actor_id',
        'operation',
        'target_type',
        'target_id',
        'reason',
        'payload',
        'correlation_id',
        'request_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
