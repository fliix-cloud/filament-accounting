<?php

namespace FilamentAccounting\Events;

use FilamentAccounting\Models\PartyBankAccount;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartyBankAccountChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public PartyBankAccount $bankAccount) {}
}
