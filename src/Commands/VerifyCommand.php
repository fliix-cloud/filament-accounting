<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Audit\AuditAnchorVerifier;
use FilamentAccounting\Audit\AuditChainVerifier;
use FilamentAccounting\Audit\JournalIntegrityVerifier;
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
        JournalIntegrityVerifier $journalVerifier,
    ): int {
        $failed = 0;
        $reports = [];

        LegalEntity::query()->orderBy('id')->each(function (LegalEntity $entity) use ($auditVerifier, $anchorVerifier, $journalVerifier, &$failed, &$reports): void {
            $report = $entity->getConnection()->transaction(function () use ($entity, $auditVerifier, $anchorVerifier, $journalVerifier): array {
                LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $ledger = $journalVerifier->verify((int) $entity->getKey());
                $auditResult = $auditVerifier->verify((int) $entity->getKey());
                $anchorResult = $anchorVerifier->verify($entity, $auditResult);

                return [
                    'legal_entity_id' => (int) $entity->getKey(),
                    'legal_entity_uuid' => (string) $entity->uuid,
                    'legal_name' => (string) $entity->legal_name,
                    'valid' => $ledger['issues'] === [] && $auditResult->isValid() && $anchorResult->isValid(),
                    'ledger' => [
                        'posted_entry_count' => $ledger['posted_entry_count'],
                        'issues' => $ledger['issues'],
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
            $failed += count($report['ledger']['issues']) + count($report['audit_chain']['issues']) + count($report['external_anchors']['issues']);
            $reports[] = $report;
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
