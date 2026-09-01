<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Services\CreateAuditAnchor;
use Illuminate\Console\Command;
use Throwable;

class CreateAuditAnchorCommand extends Command
{
    protected $signature = 'filament-accounting:audit-anchor
        {--legal-entity= : Legal-entity ID or UUID; all entities when omitted}
        {--json : Emit a machine-readable JSON report}';

    protected $description = 'Write the current audit-chain head to separately controlled immutable storage';

    public function handle(CreateAuditAnchor $createAnchor): int
    {
        $selector = $this->option('legal-entity');
        $query = LegalEntity::query()->orderBy('id');

        if (is_string($selector) && $selector !== '') {
            $query->where(function ($query) use ($selector): void {
                $query->where('uuid', $selector);

                if (ctype_digit($selector)) {
                    $query->orWhereKey((int) $selector);
                }
            });
        }

        $entities = $query->get();
        $results = [];
        $failed = 0;

        if ($entities->isEmpty() && is_string($selector) && $selector !== '') {
            $results[] = [
                'legal_entity' => $selector,
                'success' => false,
                'error' => 'Legal entity was not found.',
            ];
            $failed++;
        }

        foreach ($entities as $entity) {
            try {
                $anchor = $createAnchor->handle($entity);
                $results[] = [
                    'legal_entity_id' => (int) $entity->getKey(),
                    'legal_entity_uuid' => (string) $entity->uuid,
                    'success' => true,
                    'anchor' => $anchor->toArray(),
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    'legal_entity_id' => (int) $entity->getKey(),
                    'legal_entity_uuid' => (string) $entity->uuid,
                    'success' => false,
                    'error' => $exception->getMessage(),
                ];
                $failed++;
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'valid' => $failed === 0,
                'results' => $results,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $result) {
                if ($result['success']) {
                    $this->info(
                        "Audit chain for legal entity {$result['legal_entity_uuid']} anchored at sequence {$result['anchor']['last_sequence']} ({$result['anchor']['anchor_hash']}).",
                    );
                } else {
                    $entity = $result['legal_entity_uuid'] ?? $result['legal_entity'];
                    $this->error("Audit anchor failed for {$entity}: {$result['error']}");
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
