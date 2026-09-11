<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\LegalEntity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VerificationCompleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $report  The JSON report for this entity (schema v2).
     */
    public function __construct(
        public LegalEntity $entity,
        public array $report,
        public bool $valid,
        public int $issueCount,
        public int $pendingCount,
    ) {}
}
