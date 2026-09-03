<?php

namespace FilamentAccounting\Tests\Architecture;

use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NoFintsReferenceTest extends TestCase
{
    #[Test]
    public function runtime_code_contains_no_legacy_product_namespaces_or_bridge_registries(): void
    {
        $hits = [];

        foreach ([__DIR__.'/../../src', __DIR__.'/../../resources/views'] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname()) ?: '';
                if (preg_match('/FilamentFints|FilamentAccountingFints|filament-fints::|filament-fints\.sca\.|BankFeedDriver|BankFeedRegistry|BankSourceLinkRegistry|LegalEntityOwnerMapper/', $contents) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'Accounting runtime code must not retain a second product namespace or bridge registry.');
    }

    #[Test]
    public function composer_and_entry_points_expose_one_product_package(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['FilamentAccounting\\' => 'src/'],
            $composer['autoload']['psr-4'],
            'The product package must not own an Fhp autoload tree.',
        );
        $this->assertArrayHasKey('fliix-cloud/php-fints', $composer['require']);
        $this->assertArrayNotHasKey('fliix-cloud/filament-fints', $composer['require']);
        $this->assertArrayNotHasKey('fliix-cloud/filament-accounting-fints', $composer['require']);
        $this->assertSame(
            ['FilamentAccounting\\FilamentAccountingServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
        $this->assertFileExists(__DIR__.'/../../src/FilamentAccountingPlugin.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../src/Banking/FinTs/FilamentFintsPlugin.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../src/Banking/FinTs/FilamentFintsServiceProvider.php');
    }
}
