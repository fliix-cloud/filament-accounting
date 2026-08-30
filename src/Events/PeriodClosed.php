<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\AccountingPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PeriodClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public AccountingPeriod $period) {}
}
