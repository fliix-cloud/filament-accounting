<?php

namespace FilamentAccounting\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'filament-accounting:install {--migrate : Run package migrations}';

    protected $description = 'Install the Filament Accounting package';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'filament-accounting-config',
        ]);

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('filament-accounting installed.');
        $this->line('Next steps:');
        $this->line('  1. Register FilamentAccountingPlugin::make() on the desired Filament panel.');
        $this->line('  2. Create a Legal Entity and set ACCOUNTING_LEGAL_ENTITY_ID or bind ConfiguredLegalEntityResolver.');
        $this->line('  3. Run php artisan filament-accounting:seed-profile DE');
        $this->line('  4. Run php artisan filament-accounting:verify');

        return self::SUCCESS;
    }
}
