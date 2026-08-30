<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\JournalEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalPosted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public JournalEntry $entry) {}
}
