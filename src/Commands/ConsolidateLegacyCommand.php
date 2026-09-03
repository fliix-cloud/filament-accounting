<?php

namespace FilamentAccounting\Commands;

use DomainException;
use FilamentAccounting\Banking\Services\LegacyBankingConsolidator;
use Illuminate\Console\Command;

class ConsolidateLegacyCommand extends Command
{
    protected $signature = 'filament-accounting:consolidate-legacy
        {--dry-run : Inspect legacy data without changing it}
        {--json : Emit a machine-readable report}';

    protected $description = 'Safely consolidate legacy FinTS and bridge data into canonical accounting records';

    public function handle(LegacyBankingConsolidator $consolidator): int
    {
        try {
            $report = $this->option('dry-run')
                ? $consolidator->analyze()
                : $consolidator->consolidate();
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Legacy table', 'Rows'],
                collect($report['counts'])->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
            );

            foreach ($report['blockers'] as $blocker) {
                $this->error("{$blocker['table']} #{$blocker['id']}: {$blocker['reason']}");
            }

            $this->newLine();
            $this->line('Legacy tables are retained and are not deleted by this command.');
        }

        return $report['blockers'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
