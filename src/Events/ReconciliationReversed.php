<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\Reconciliation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReconciliationReversed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Reconciliation $reconciliation) {}
}
