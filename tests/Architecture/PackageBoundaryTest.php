<?php

namespace FilamentAccounting\Tests\Architecture;

use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackageBoundaryTest extends TestCase
{
    #[Test]
    public function composer_and_entry_points_expose_one_product_package(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['FilamentAccounting\\' => 'src/'],
            $composer['autoload']['psr-4'],
            'The product package must not own the protocol namespace.',
        );
        $this->assertSame('dev-master', $composer['require']['nemiah/php-fints'] ?? null);
        $this->assertArrayNotHasKey('repositories', $composer);
        $this->assertSame(
            ['FilamentAccounting\\FilamentAccountingServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
        $this->assertFileExists(__DIR__.'/../../src/FilamentAccountingPlugin.php');
    }
}
