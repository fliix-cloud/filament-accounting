<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditAnchorVerifier;
use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Enums\JournalStatus;
use FilamentAccounting\Models\JournalEntry;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'filament-accounting:verify
        {--json : Emit a machine-readable JSON report}';

    protected $description = 'Verify ledger and audit-chain integrity for all legal entities';

    public function handle(
        AuditChainVerifier $auditVerifier,
        AuditAnchorVerifier $anchorVerifier,
    ): int {
        $failed = 0;
        $reports = [];

        LegalEntity::query()->orderBy('id')->each(function (LegalEntity $entity) use ($auditVerifier, $anchorVerifier, &$failed, &$reports): void {
            $entries = JournalEntry::query()
                ->where('legal_entity_id', $entity->getKey())
                ->where('status', JournalStatus::Posted)
                ->with('lines')
                ->get();
            $ledgerIssues = [];

            foreach ($entries as $entry) {
                $debits = (int) $entry->lines->sum('base_debit_minor');
                $credits = (int) $entry->lines->sum('base_credit_minor');

                if ($debits !== $credits) {
                    $ledgerIssues[] = [
                        'code' => 'journal_unbalanced',
                        'message' => "Unbalanced journal {$entry->uuid}: {$debits} vs {$credits}.",
                        'journal_uuid' => (string) $entry->uuid,
                    ];
                    $failed++;
                }

                if ($entry->lines->count() < 2) {
                    $ledgerIssues[] = [
                        'code' => 'journal_too_few_lines',
                        'message' => "Journal {$entry->uuid} has fewer than two lines.",
                        'journal_uuid' => (string) $entry->uuid,
                    ];
                    $failed++;
                }
            }

            $auditResult = $auditVerifier->verify((int) $entity->getKey());
            $anchorResult = $anchorVerifier->verify($entity, $auditResult);
            $failed += count($auditResult->issues) + count($anchorResult->issues);

            $reports[] = [
                'legal_entity_id' => (int) $entity->getKey(),
                'legal_entity_uuid' => (string) $entity->uuid,
                'legal_name' => (string) $entity->legal_name,
                'valid' => $ledgerIssues === [] && $auditResult->isValid() && $anchorResult->isValid(),
                'ledger' => [
                    'posted_entry_count' => $entries->count(),
                    'issues' => $ledgerIssues,
                ],
                'audit_chain' => [
                    'event_count' => $auditResult->eventCount,
                    'last_sequence' => $auditResult->lastSequence,
                    'head_hash' => $auditResult->headHash,
                    'issues' => array_map(fn ($issue): array => $issue->toArray(), $auditResult->issues),
                ],
                'external_anchors' => [
                    'required' => (bool) config('filament-accounting.audit.anchor.required', false),
                    'immutable_storage_attested' => (bool) config('filament-accounting.audit.anchor.immutable_storage_attested', false),
                    'anchor_count' => $anchorResult->anchorCount,
                    'last_anchored_sequence' => $anchorResult->lastAnchoredSequence,
                    'latest_anchor_hash' => $anchorResult->latestAnchorHash,
                    'issues' => array_map(fn ($issue): array => $issue->toArray(), $anchorResult->issues),
                ],
            ];
        });

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'valid' => $failed === 0,
                'issue_count' => $failed,
                'legal_entities' => $reports,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($reports as $report) {
            foreach ($report['ledger']['issues'] as $issue) {
                $this->error("Ledger [{$issue['code']}] for {$report['legal_name']}: {$issue['message']}");
            }

            foreach ($report['audit_chain']['issues'] as $issue) {
                $sequence = $issue['sequence'] === null ? '' : " at sequence {$issue['sequence']}";
                $this->error("Audit [{$issue['code']}] for {$report['legal_name']}{$sequence}: {$issue['message']}");
            }

            foreach ($report['external_anchors']['issues'] as $issue) {
                $sequence = $issue['sequence'] === null ? '' : " at sequence {$issue['sequence']}";
                $this->error("Audit anchor [{$issue['code']}] for {$report['legal_name']}{$sequence}: {$issue['message']}");
            }
        }

        if ($failed > 0) {
            $this->error("Integrity check failed with {$failed} issue(s).");

            return self::FAILURE;
        }

        $this->info('Accounting integrity check passed.');

        return self::SUCCESS;
    }
}
