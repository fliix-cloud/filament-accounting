<?php

namespace FilamentAccounting\Audit;

final readonly class AuditChainIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public ?int $sequence = null,
    ) {}

    /**
     * @return array{code: string, message: string, sequence: int|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'sequence' => $this->sequence,
        ];
    }
}
