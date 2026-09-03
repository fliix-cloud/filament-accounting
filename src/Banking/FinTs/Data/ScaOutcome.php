<?php

namespace FilamentAccounting\Banking\FinTs\Data;

use Fhp\BaseAction;
use FilamentAccounting\Banking\FinTs\Enums\ScaSessionState;
use FilamentAccounting\Banking\FinTs\Models\StrongAuthenticationSession;

final readonly class ScaOutcome
{
    public function __construct(
        public ScaSessionState $state,
        public ?StrongAuthenticationSession $session = null,
        public ?BaseAction $action = null,
        public ?string $bankMessage = null,
    ) {}

    public function isDone(): bool
    {
        return $this->state === ScaSessionState::Done;
    }

    public function requiresUser(): bool
    {
        return $this->session !== null && $this->state->isOpen();
    }
}
