<?php

namespace FilamentAccounting\Reconciliation;

use FilamentAccounting\Contracts\ReconciliationMatcher;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\OpenItem;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Models\ReconciliationLearningRule;
use FilamentAccounting\Reconciliation\Data\MatchSuggestion;
use FilamentAccounting\Support\Sepa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class DeterministicReconciliationMatcher implements ReconciliationMatcher
{
    public function suggest(BankStatementLine $line): array
    {
        $items = OpenItem::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('is_reversed', false)
            ->with(['document.party', 'party.bankAccounts'])
            ->get()
            ->filter(fn (OpenItem $item): bool => $item->remainingMinor() !== 0);

        $incoming = $line->amount_minor > 0;
        $counterpartyIban = filled($line->counterparty_iban)
            ? Sepa::normalizeIban((string) $line->counterparty_iban)
            : null;
        $learnedPartyIds = ReconciliationLearningRule::matching($line, 'party')
            ->pluck('target_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $scored = [];

        foreach ($items as $item) {
            if ($incoming && $item->kind->value !== 'receivable') {
                continue;
            }
            if (! $incoming && $item->kind->value !== 'payable') {
                continue;
            }

            $score = 0;
            $reasons = [];
            $document = $item->document;
            $purpose = Str::lower((string) $line->purpose.' '.$line->payment_reference.' '.$line->end_to_end_id);

            if (filled($line->end_to_end_id) && $document instanceof Document && filled($document->number)
                && str_contains(Str::lower((string) $line->end_to_end_id), Str::lower((string) $document->number))) {
                $score += 100;
                $reasons[] = 'end_to_end';
            }

            if ($document instanceof Document && filled($document->number) && str_contains($purpose, Str::lower((string) $document->number))) {
                $score += 80;
                $reasons[] = 'document_number';
            }

            if ($document instanceof Document && filled($document->supplier_invoice_number)
                && str_contains($purpose, Str::lower((string) $document->supplier_invoice_number))) {
                $score += 80;
                $reasons[] = 'document_number';
            }

            $remaining = $item->remainingMinor();
            if (abs($line->amount_minor) === abs($remaining) && $line->currency === $item->currency) {
                $score += 60;
                $reasons[] = 'amount';
            }

            $ibanMatches = $counterpartyIban !== null && $item->party?->bankAccounts->contains(
                fn (PartyBankAccount $account): bool => Sepa::normalizeIban($account->iban) === $counterpartyIban,
            );
            if ($ibanMatches) {
                $score += 40;
                $reasons[] = 'iban';
            }

            if ($item->party_id && in_array((int) $item->party_id, $learnedPartyIds, true)) {
                $score += 35;
                $reasons[] = 'learned_rule';
            }

            $name = $this->normalizedName((string) ($item->party?->displayLabel() ?? ''));
            $counterparty = $this->normalizedName((string) $line->counterparty_name);
            if ($name !== '' && $counterparty !== '' && (str_contains($counterparty, $name) || str_contains($name, $counterparty))) {
                $score += 30;
                $reasons[] = 'name';
            }

            if ($line->booking_date && $item->due_on) {
                $days = abs(Carbon::parse($line->booking_date)->diffInDays(Carbon::parse($item->due_on)));
                if ($days <= 5) {
                    $score += 20;
                    $reasons[] = 'date_proximity';
                } elseif ($days <= 14) {
                    $score += 10;
                    $reasons[] = 'date_proximity';
                }
            }

            $score += 20;
            $reasons[] = 'direction';

            if ($score >= 40) {
                $scored[] = new MatchSuggestion('open_item', (int) $item->getKey(), $score, array_values(array_unique($reasons)));
            }
        }

        usort($scored, fn (MatchSuggestion $a, MatchSuggestion $b): int => $b->score <=> $a->score);

        if (count($scored) >= 2 && $scored[0]->score === $scored[1]->score) {
            return array_map(
                fn (MatchSuggestion $suggestion): MatchSuggestion => new MatchSuggestion(
                    $suggestion->targetType,
                    $suggestion->targetId,
                    $suggestion->score,
                    $suggestion->reasons,
                    true,
                ),
                $scored,
            );
        }

        return $scored;
    }

    private function normalizedName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
