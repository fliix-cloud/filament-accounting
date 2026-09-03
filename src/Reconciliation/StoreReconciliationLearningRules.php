<?php

namespace FilamentAccounting\Reconciliation;

use FilamentAccounting\Enums\SplitPurpose;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\Reconciliation;
use FilamentAccounting\Models\ReconciliationLearningRule;
use FilamentAccounting\Models\ReconciliationSplit;

final class StoreReconciliationLearningRules
{
    public function handle(Reconciliation $reconciliation, BankStatementLine $line): void
    {
        $matchValues = ReconciliationLearningRule::matchValues($line);
        if ($matchValues === []) {
            return;
        }

        $reconciliation->loadMissing([
            'splits.openItem.party',
            'splits.postingRuleVersion.postingRule',
            'splits.ledgerAccount',
        ]);

        foreach ($reconciliation->splits as $split) {
            if (! $split instanceof ReconciliationSplit) {
                continue;
            }

            $target = $this->target($split);
            if ($target === null) {
                continue;
            }

            foreach ($matchValues as $matchType => $matchValue) {
                $rule = ReconciliationLearningRule::query()->firstOrNew([
                    'legal_entity_id' => $line->legal_entity_id,
                    'direction' => $line->isIncoming() ? 'incoming' : 'outgoing',
                    'match_type' => $matchType,
                    'match_value' => $matchValue,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'],
                ]);
                $rule->fill([
                    'target_label' => $target['label'],
                    'confirmed_count' => $rule->exists ? $rule->confirmed_count + 1 : 1,
                    'last_confirmed_at' => now(),
                    'is_active' => true,
                ]);
                $rule->save();
            }
        }
    }

    /** @return array{type: string, id: int, label: string}|null */
    private function target(ReconciliationSplit $split): ?array
    {
        if ($split->purpose === SplitPurpose::SettleOpenItem && $split->openItem instanceof OpenItem) {
            $party = $split->openItem->party;

            return $party === null ? null : [
                'type' => 'party',
                'id' => (int) $party->getKey(),
                'label' => $party->displayLabel(),
            ];
        }

        if ($split->purpose === SplitPurpose::PostingRule && $split->postingRuleVersion instanceof PostingRuleVersion) {
            $rule = $split->postingRuleVersion->postingRule;

            return $rule === null ? null : [
                'type' => 'posting_rule',
                'id' => (int) $rule->getKey(),
                'label' => (string) $rule->label,
            ];
        }

        if ($split->purpose === SplitPurpose::LedgerAccount && $split->ledgerAccount !== null) {
            return [
                'type' => 'ledger_account',
                'id' => (int) $split->ledgerAccount->getKey(),
                'label' => $split->ledgerAccount->label(),
            ];
        }

        return null;
    }
}
