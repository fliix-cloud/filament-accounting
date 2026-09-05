<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Events\PeriodReopened;
use FilamentAccounting\Exceptions\ClosedPeriodException;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;

final class ReopenAccountingPeriod
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AccountingPeriod $period, string $reason): AccountingPeriod
    {
        $this->authorizer->authorize('reopen_periods', $period);
        $this->scope->assertSame((int) $period->legal_entity_id);

        $reason = trim($reason);
        if ($reason === '') {
            throw new ClosedPeriodException(__('filament-accounting::errors.reason_required'));
        }

        return $period->getConnection()->transaction(function () use ($period, $reason): AccountingPeriod {
            LegalEntity::query()->lockForUpdate()->findOrFail($period->getRawOriginal('legal_entity_id'));
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $this->scope->assertModel($period);
            $this->authorizer->authorize('reopen_periods', $period);
            $before = $period->state;
            if ($before === PeriodState::Open) {
                return $period;
            }
            $actor = $this->actors->resolve();
            $period->state = PeriodState::Open;
            $period->reopened_at = now();
            $period->reopened_by_type = $actor?->getMorphClass();
            $period->reopened_by_id = $actor ? (string) $actor->getKey() : null;
            $period->reopen_reason = $reason;
            $period->save();

            $this->audit->log($period->legalEntity, 'period.reopened', $period, [
                'before' => $before->value,
                'after' => PeriodState::Open->value,
            ], $reason);

            $period->getConnection()->afterCommit(fn () => PeriodReopened::dispatch($period->fresh()));

            return $period;
        });
    }
}
