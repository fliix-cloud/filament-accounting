<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Enums\ChallengeType;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Enums\VopMatchType;
use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property int $bank_connection_id
 * @property ScaOperationType $operation_type
 * @property string|null $related_type
 * @property int|null $related_id
 * @property ScaSessionState $state
 * @property string|null $encrypted_fints_state
 * @property string|null $encrypted_action
 * @property string|null $encrypted_challenge_text
 * @property string|null $encrypted_challenge_payload
 * @property ChallengeType|null $challenge_type
 * @property string|null $challenge_mime
 * @property string|null $tan_medium_name
 * @property VopMatchType|null $vop_match
 * @property string|null $vop_information
 * @property Carbon|null $next_poll_at
 * @property Carbon|null $first_poll_at
 * @property int $poll_attempts
 * @property int|null $max_poll_attempts
 * @property int|null $poll_interval_seconds
 * @property Carbon|null $expires_at
 * @property string|null $return_url
 * @property string|null $last_status_message
 * @property string|null $confirmed_by_type
 * @property string|null $confirmed_by_id
 * @property Carbon|null $cleared_at
 * @property BankConnection $connection
 */
class StrongAuthenticationSession extends Model
{
    use BelongsToLegalEntity;
    use UsesPackageConnection;

    protected $table = 'fints_sca_sessions';

    protected $hidden = [
        'encrypted_fints_state',
        'encrypted_action',
        'encrypted_challenge_text',
        'encrypted_challenge_payload',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->legal_entity_id ??= BankConnection::query()
                ->whereKey($model->bank_connection_id)
                ->value('legal_entity_id');
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function openFor(Model $related): ?self
    {
        return static::query()
            ->where('related_type', $related->getMorphClass())
            ->where('related_id', $related->getKey())
            ->whereIn('state', array_values(array_filter(
                ScaSessionState::cases(),
                fn (ScaSessionState $state): bool => $state->isOpen(),
            )))
            ->latest('id')
            ->first();
    }

    protected function casts(): array
    {
        return [
            'operation_type' => ScaOperationType::class,
            'state' => ScaSessionState::class,
            'challenge_type' => ChallengeType::class,
            'vop_match' => VopMatchType::class,
            'encrypted_fints_state' => 'encrypted',
            'encrypted_action' => 'encrypted',
            'encrypted_challenge_payload' => 'encrypted',
            'encrypted_challenge_text' => 'encrypted',
            'next_poll_at' => 'datetime',
            'first_poll_at' => 'datetime',
            'expires_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    public function confirmedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->state?->isOpen() === true && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function clearSensitiveState(): void
    {
        $this->encrypted_fints_state = null;
        $this->encrypted_action = null;
        $this->encrypted_challenge_payload = null;
        $this->encrypted_challenge_text = null;
        $this->cleared_at = now();
        $this->save();
    }
}
