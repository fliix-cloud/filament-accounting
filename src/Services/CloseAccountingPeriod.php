<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Events\PeriodClosed;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($period, $hard): AccountingPeriod {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $actor = $this->actors->resolve();
            $period->state = $hard ? PeriodState::HardClosed : PeriodState::SoftClosed;
            $period->closed_at = now();
            $period->closed_by_type = $actor?->getMorphClass();
            $period->closed_by_id = $actor ? (string) $actor->getKey() : null;
            $period->save();

            $this->audit->log($period->legalEntity, 'period.closed', $period, [
                'hard' => $hard,
            ]);

            DB::afterCommit(fn () => PeriodClosed::dispatch($period->fresh()));

            return $period;
        });
    }
}
