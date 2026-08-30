<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Reconciliation\Data\MatchSuggestion;

interface ReconciliationMatcher
{
    /**
     * @return list<MatchSuggestion>
     */
    public function suggest(BankStatementLine $line): array;
}
