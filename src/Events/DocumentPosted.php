<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentPosted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Document $document) {}
}
