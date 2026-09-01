<?php

namespace FilamentAccounting\Audit;

final readonly class AuditChainIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public ?int $sequence = null,
    ) {}
}
