<?php

namespace FilamentAccounting\Banking\FinTs\Data;

use Fhp\BaseAction;

final readonly class PersistedFintsState
{
    public function __construct(
        public string $fintsInstance,
        public ?string $serializedAction = null,
    ) {}

    public static function fromClientAndAction(string $fintsInstance, ?BaseAction $action): self
    {
        return new self(
            $fintsInstance,
            $action !== null ? serialize($action) : null,
        );
    }
}
