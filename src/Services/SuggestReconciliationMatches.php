<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\ReconciliationMatcher;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Reconciliation\Data\MatchSuggestion;

final class SuggestReconciliationMatches
{
    public function __construct(
        private readonly ReconciliationMatcher $matcher,
        private readonly LegalEntityScope $scope,
    ) {}

    /**
     * @return list<MatchSuggestion>
     */
    public function handle(BankStatementLine $line): array
    {
        $this->scope->assertSame((int) $line->legal_entity_id);

        return $this->matcher->suggest($line);
    }
}
