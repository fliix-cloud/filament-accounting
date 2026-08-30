<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Ledger\ReverseJournalCommand;
use FilamentAccounting\Models\JournalEntry;

interface LedgerEngine
{
    public function post(PostJournalCommand $command): JournalEntry;

    public function reverse(ReverseJournalCommand $command): JournalEntry;
}
