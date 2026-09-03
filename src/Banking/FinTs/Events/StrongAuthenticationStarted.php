<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class StrongAuthenticationStarted
{
    public function __construct(
        public readonly string $sessionUuid,
        public readonly int $bankConnectionId,
        public readonly string $state,
    ) {}
}
