<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\PeriodState;
use FilamentAccounting\Models\AccountingPeriod;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Support\Carbon;

final class ResolveAccountingPeriod
{
    public function covering(LegalEntity $entity, string $date, bool $lock = false): AccountingPeriod
    {
        $day = Carbon::parse($date)->toDateString();

        $query = AccountingPeriod::query()
            ->where('legal_entity_id', $entity->getKey())
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day);

        if ($lock) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if ($period instanceof AccountingPeriod) {
            return $period;
        }

        $this->ensureYear($entity, $day);

        $query = AccountingPeriod::query()
            ->where('legal_entity_id', $entity->getKey())
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day);

        if ($lock) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if (! $period instanceof AccountingPeriod) {
            throw new \RuntimeException("Unable to resolve accounting period for {$day}.");
        }

        return $period;
    }

    public function ensureYear(LegalEntity $entity, string $date): void
    {
        [$fiscalYear, $startMonth] = $this->fiscalYear($entity, $date);
        $start = Carbon::create($fiscalYear, $startMonth, 1)->startOfDay();

        for ($i = 0; $i < 12; $i++) {
            $startsOn = $start->copy()->addMonthsNoOverflow($i);
            $endsOn = $startsOn->copy()->endOfMonth();
            $periodNumber = $i + 1;

            AccountingPeriod::query()->firstOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'fiscal_year' => $fiscalYear,
                    'period_number' => $periodNumber,
                ],
                [
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $endsOn->toDateString(),
                    'state' => PeriodState::Open,
                ]
            );
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function fiscalYear(LegalEntity $entity, string $date): array
    {
        $day = Carbon::parse($date);
        $startMonth = max(1, min(12, (int) $entity->fiscal_year_start_month));
        $year = (int) $day->year;
        $month = (int) $day->month;

        if ($month >= $startMonth) {
            return [$year, $startMonth];
        }

        return [$year - 1, $startMonth];
    }
}
