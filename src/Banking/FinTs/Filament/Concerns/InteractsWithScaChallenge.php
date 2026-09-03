<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use FilamentAccounting\Banking\FinTs\Data\ScaOutcome;
use FilamentAccounting\Banking\FinTs\Enums\ScaOperationType;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Filament\Pages\StrongAuthentication;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Ownership\LegalEntityBankScope as OwnerScope;
use FilamentAccounting\Banking\FinTs\Services\StrongAuthenticationCoordinator;
use FilamentAccounting\Banking\FinTs\Support\ErrorMapper;
use FilamentAccounting\Banking\FinTs\Support\FintsUi;
use FilamentAccounting\Contracts\AccountingActorResolver as BankActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer as BankAuthorizer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * @mixin Page
 */
trait InteractsWithScaChallenge
{
    #[Locked]
    public ?string $scaSessionUuid = null;

    public string $scaTan = '';

    public function openSca(ScaOutcome $outcome): bool
    {
        if (! $outcome->requiresUser() || ! $outcome->session) {
            return false;
        }

        $this->scaSessionUuid = $outcome->session->uuid;
        $this->scaTan = '';

        return true;
    }

    protected function scaPageUrl(): ?string
    {
        if (! filled($this->scaSessionUuid)) {
            return null;
        }

        return StrongAuthentication::getUrl(['record' => $this->scaSessionUuid]);
    }

    public function pollScaChallenge(): void
    {
        $session = $this->scaSession();
        if (! $session instanceof StrongAuthenticationSession || ! $session->state->isOpen()) {
            return;
        }

        if (! in_array($session->state, [ScaSessionState::NeedsDecoupled, ScaSessionState::NeedsPolling], true)) {
            return;
        }

        if ($session->next_poll_at?->isFuture()) {
            return;
        }

        $this->advanceScaChallenge();
    }

    public function submitScaTan(): void
    {
        $tan = $this->scaTan;
        $this->scaTan = '';
        if (! filled($tan)) {
            return;
        }

        $this->advanceScaChallenge(fn (StrongAuthenticationCoordinator $c, BankConnection $connection, $actor) => $c->submitTan(
            (string) $this->scaSessionUuid,
            $tan,
            $connection,
            $actor,
        ));
    }

    public function confirmScaVop(): void
    {
        $this->advanceScaChallenge(fn (StrongAuthenticationCoordinator $c, BankConnection $connection, $actor) => $c->confirmVop(
            (string) $this->scaSessionUuid,
            $connection,
            $actor,
        ));
    }

    public function confirmScaInApp(): void
    {
        $this->advanceScaChallenge();
    }

    public function closeScaModal(): void
    {
        $this->scaSessionUuid = null;
        $this->scaTan = '';
    }

    public function getFooter(): ?View
    {
        return view('filament-accounting::banking/fints/modals.sca-host', [
            'session' => $this->scaSession(),
        ]);
    }

    /**
     * @param  (callable(StrongAuthenticationCoordinator, BankConnection, mixed): ScaOutcome)|null  $callback
     */
    protected function advanceScaChallenge(?callable $callback = null): void
    {
        $session = $this->scaSession();
        if (! $session instanceof StrongAuthenticationSession) {
            return;
        }

        $owners = app(OwnerScope::class);
        $actor = app(BankActorResolver::class)->resolve();
        app(BankAuthorizer::class)->authorize('confirm_bank_sca', $session);
        $connection = $owners->connections()->whereKey($session->bank_connection_id)->first();
        if (! $connection instanceof BankConnection) {
            return;
        }

        $callback ??= function (StrongAuthenticationCoordinator $coordinator, BankConnection $connection, $actor) use ($session): ScaOutcome {
            return $session->state === ScaSessionState::NeedsPolling
                ? $coordinator->poll($session->uuid, $connection, $actor)
                : $coordinator->checkDecoupled($session->uuid, $connection, $actor);
        };

        try {
            $outcome = $callback(app(StrongAuthenticationCoordinator::class), $connection, $actor);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Polling is not yet permitted')) {
                return;
            }

            FintsUi::notifyFailure($e);
            $failed = $session->fresh() ?? $session;
            if ($failed->state->isOpen()) {
                $failed->state = ScaSessionState::Failed;
                $failed->last_status_message = ErrorMapper::map($e)->userMessage();
                $failed->clearSensitiveState();
            }
            $this->closeScaModal();

            return;
        }

        $fresh = $session->fresh();
        $operation = $session->operation_type;
        if ($fresh instanceof StrongAuthenticationSession) {
            $this->scaSessionUuid = $fresh->uuid;
            $operation = $fresh->operation_type;
        }

        if ($outcome->isDone()) {
            $this->closeScaModal();
            if ($this->shouldNotifyScaCompleted($operation)) {
                Notification::make()
                    ->title($this->scaCompletedNotification($operation))
                    ->success()
                    ->send();
            }
            $this->afterScaCompleted($outcome);

            return;
        }

        if ($outcome->session) {
            $this->scaSessionUuid = $outcome->session->uuid;
        }
    }

    protected function afterScaCompleted(ScaOutcome $outcome): void
    {
        unset($outcome);
    }

    protected function shouldNotifyScaCompleted(ScaOperationType $operation): bool
    {
        unset($operation);

        return true;
    }

    protected function scaSession(): ?StrongAuthenticationSession
    {
        if (! filled($this->scaSessionUuid)) {
            return null;
        }

        return StrongAuthenticationSession::query()
            ->where('uuid', $this->scaSessionUuid)
            ->whereIn('bank_connection_id', app(OwnerScope::class)->connections()->select('id'))
            ->first();
    }

    protected function scaCompletedNotification(ScaOperationType $type): string
    {
        return match ($type) {
            ScaOperationType::TestConnection => __('filament-accounting::banking/fints/notifications.connection_tested'),
            ScaOperationType::SyncAccounts => __('filament-accounting::banking/fints/notifications.accounts_synced'),
            ScaOperationType::SyncBalances => __('filament-accounting::banking/fints/notifications.balances_synced'),
            ScaOperationType::SyncTransactions => __('filament-accounting::banking/fints/notifications.transactions_synced'),
            ScaOperationType::Transfer => __('filament-accounting::banking/fints/notifications.transfer_submitted'),
            ScaOperationType::DirectDebit => __('filament-accounting::banking/fints/notifications.direct_debit_submitted'),
            default => __('filament-accounting::banking/fints/notifications.sca_completed'),
        };
    }
}
