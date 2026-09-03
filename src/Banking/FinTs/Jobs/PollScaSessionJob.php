<?php

namespace FilamentAccounting\Banking\FinTs\Jobs;

use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Models\BankConnection;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;
use FilamentAccounting\Banking\FinTs\Services\StrongAuthenticationCoordinator;
use FilamentAccounting\Contracts\AccountingTenancyContextActivator as BankTenancyContextActivator;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollScaSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $sessionUuid,
        public readonly int $bankConnectionId,
        public readonly int $legalEntityId,
    ) {
        $this->onQueue((string) config('filament-accounting.banking.fints.sync.queue', 'default'));
    }

    public static function fromSession(StrongAuthenticationSession $session): self
    {
        $connection = $session->connection;

        return new self(
            $session->uuid,
            $connection->id,
            $connection->legal_entity_id,
        );
    }

    public function handle(StrongAuthenticationCoordinator $coordinator, BankTenancyContextActivator $activator): void
    {
        $activator->activate(LegalEntity::class, (string) $this->legalEntityId);

        // Query only after restoring the durable legal-entity context. Queue
        // payloads contain scalar identifiers, never serialized tenant models.
        $connection = BankConnection::query()
            ->where('legal_entity_id', $this->legalEntityId)
            ->findOrFail($this->bankConnectionId);

        $session = StrongAuthenticationSession::query()
            ->where('legal_entity_id', $this->legalEntityId)
            ->where('uuid', $this->sessionUuid)
            ->where('bank_connection_id', $connection->id)
            ->first();

        if ($session === null || ! $session->state->isOpen()) {
            return;
        }

        if ($session->state === ScaSessionState::NeedsDecoupled) {
            $coordinator->checkDecoupled($session->uuid, $connection);
        } elseif ($session->state === ScaSessionState::NeedsPolling) {
            $coordinator->poll($session->uuid, $connection);
        }
    }
}
