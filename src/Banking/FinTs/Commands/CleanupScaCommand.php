<?php

namespace FilamentAccounting\Banking\FinTs\Commands;

use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use Illuminate\Console\Command;

class CleanupScaCommand extends Command
{
    protected $signature = 'filament-accounting:cleanup-sca';

    protected $description = 'Expire and clear sensitive FinTS SCA session state';

    public function handle(): int
    {
        $retention = (int) config('filament-accounting.banking.fints.sync.retention_days', 30);

        $open = StrongAuthenticationSession::query()
            ->whereIn('state', [
                ScaSessionState::NeedsTan->value,
                ScaSessionState::NeedsDecoupled->value,
                ScaSessionState::NeedsVop->value,
                ScaSessionState::NeedsPolling->value,
            ])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($open as $session) {
            $session->state = ScaSessionState::Expired;
            $session->clearSensitiveState();
        }

        StrongAuthenticationSession::query()
            ->whereIn('state', [
                ScaSessionState::Done->value,
                ScaSessionState::Failed->value,
                ScaSessionState::Expired->value,
            ])
            ->where('updated_at', '<', now()->subDays($retention))
            ->delete();

        $this->info('SCA cleanup complete.');

        return self::SUCCESS;
    }
}
