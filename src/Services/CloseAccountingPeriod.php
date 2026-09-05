<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Events\PeriodClosed;
use FilamentAccounting\Exceptions\ClosedPeriodException;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;

final class CloseAccountingPeriod
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AccountingPeriod $period, bool $hard = true): AccountingPeriod
    {
        $this->authorizer->authorize('close_periods', $period);
        $this->scope->assertSame((int) $period->legal_entity_id);

        return $period->getConnection()->transaction(function () use ($period, $hard): AccountingPeriod {
            LegalEntity::query()->lockForUpdate()->findOrFail($period->getRawOriginal('legal_entity_id'));
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $this->scope->assertModel($period);
            $this->authorizer->authorize('close_periods', $period);
            if ($period->isHardClosed() && ! $hard) {
                throw new ClosedPeriodException(__('filament-accounting::errors.period_closed'));
            }
            $before = $period->state;
            $after = $hard ? PeriodState::HardClosed : PeriodState::SoftClosed;
            if ($before === $after) {
                return $period;
            }
            $actor = $this->actors->resolve();
            $period->state = $after;
            $period->closed_at = now();
            $period->closed_by_type = $actor?->getMorphClass();
            $period->closed_by_id = $actor ? (string) $actor->getKey() : null;
            $period->save();

            $this->audit->log($period->legalEntity, 'period.closed', $period, [
                'hard' => $hard,
                'before' => $before->value,
                'after' => $after->value,
            ]);

            $period->getConnection()->afterCommit(fn () => PeriodClosed::dispatch($period->fresh()));

            return $period;
        });
    }
}
