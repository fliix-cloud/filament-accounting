<?php

namespace FilamentAccounting\Commands;

use FilamentAccounting\Models\LegalEntity;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'filament-accounting:install
        {--migrate : Run package migrations}
        {--country=DE : Opinionated company profile to prepare}';

    protected $description = 'Install the Filament Accounting package';

    public function handle(): int
    {
        $country = strtoupper((string) $this->option('country'));
        if ($country !== 'DE') {
            $this->error('Version 0.1 supports companies established in Germany only.');

            return self::FAILURE;
        }

        $this->call('vendor:publish', [
            '--tag' => 'filament-accounting-config',
        ]);

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        if (LegalEntity::query()->exists()) {
            $this->call('filament-accounting:seed-profile', ['profile' => $country]);
        }

        $this->newLine();
        $this->info('filament-accounting installed.');
        $this->line('Next steps:');
        $this->line('  1. Register FilamentAccountingPlugin::make() on the desired Filament panel.');
        $this->line('  2. Complete the company setup wizard; the DE profile is seeded automatically.');
        $this->line('  3. Configure FINTS_PRODUCT_ID before connecting a bank.');
        $this->line('  4. Run php artisan filament-accounting:verify.');

        return self::SUCCESS;
    }
}
