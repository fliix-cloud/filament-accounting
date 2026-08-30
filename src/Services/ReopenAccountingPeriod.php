<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Events\PeriodReopened;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($period, $reason): AccountingPeriod {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $actor = $this->actors->resolve();
            $period->state = PeriodState::Open;
            $period->reopened_at = now();
            $period->reopened_by_type = $actor?->getMorphClass();
            $period->reopened_by_id = $actor ? (string) $actor->getKey() : null;
            $period->reopen_reason = $reason;
            $period->save();

            $this->audit->log($period->legalEntity, 'period.reopened', $period, [], $reason);

            DB::afterCommit(fn () => PeriodReopened::dispatch($period->fresh()));

            return $period;
        });
    }
}
