<?php

namespace FilamentAccounting\Banking\FinTs\Events;

class StrongAuthenticationExpired
{
    public function __construct(
        public readonly string $sessionUuid,
        public readonly int $bankConnectionId,
    ) {}
}
