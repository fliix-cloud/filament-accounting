<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Compliance\ComplianceProfileRegistry;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Console\Command;

class SeedProfileCommand extends Command
{
    protected $signature = 'filament-accounting:seed-profile {profile=DE} {--entity= : Legal entity UUID or ID}';

    protected $description = 'Seed a compliance profile (chart, tax codes, posting rules) for a legal entity';

    public function handle(ComplianceProfileRegistry $registry, LegalEntityScope $scope): int
    {
        $profileKey = (string) $this->argument('profile');
        $entityOption = $this->option('entity');

        $entity = null;
        if (filled($entityOption)) {
            $entity = LegalEntity::query()
                ->where(function ($query) use ($entityOption): void {
                    $query->where('uuid', $entityOption)->orWhere('id', $entityOption);
                })
                ->first();
        }

        $entity ??= $scope->current() ?? LegalEntity::query()->orderBy('id')->first();

        if (! $entity instanceof LegalEntity) {
            $this->error('No legal entity found. Create one first.');

            return self::FAILURE;
        }

        $registry->get($profileKey)->seed($entity);
        $entity->compliance_profile_key = $profileKey;
        $entity->save();

        $this->info("Seeded profile [{$profileKey}] for {$entity->legal_name}.");

        return self::SUCCESS;
    }
}
