<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'filament-accounting:verify';

    protected $description = 'Verify ledger and audit-chain integrity for all legal entities';

    public function handle(AuditChainVerifier $auditVerifier): int
    {
        $failed = 0;

        LegalEntity::query()->orderBy('id')->each(function (LegalEntity $entity) use ($auditVerifier, &$failed): void {
            $entries = JournalEntry::query()
                ->where('legal_entity_id', $entity->getKey())
                ->where('status', JournalStatus::Posted)
                ->with('lines')
                ->get();

            foreach ($entries as $entry) {
                $debits = (int) $entry->lines->sum('base_debit_minor');
                $credits = (int) $entry->lines->sum('base_credit_minor');
                if ($debits !== $credits) {
                    $this->error("Unbalanced journal {$entry->uuid} for {$entity->legal_name}: {$debits} vs {$credits}");
                    $failed++;
                }
                if ($entry->lines->count() < 2) {
                    $this->error("Journal {$entry->uuid} has fewer than two lines.");
                    $failed++;
                }
            }

            $auditResult = $auditVerifier->verify((int) $entity->getKey());

            foreach ($auditResult->issues as $issue) {
                $sequence = $issue->sequence === null ? '' : " at sequence {$issue->sequence}";
                $this->error("Audit [{$issue->code}] for {$entity->legal_name}{$sequence}: {$issue->message}");
                $failed++;
            }
        });

        if ($failed > 0) {
            $this->error("Integrity check failed with {$failed} issue(s).");

            return self::FAILURE;
        }

        $this->info('Accounting integrity check passed.');

        return self::SUCCESS;
    }
}
