<?php

namespace FilamentAccounting\Tests\Architecture;

use Filament\Panel;
use Filament\Resources\Resource;
use FilamentAccounting\FilamentAccountingPlugin;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class PackageBoundaryTest extends TestCase
{
    #[Test]
    public function every_concrete_package_resource_is_registered_when_all_features_are_enabled(): void
    {
        config()->set('filament-accounting.features', array_fill_keys(array_keys(config('filament-accounting.features')), true));
        $panel = Panel::make()->id('resource-boundary');
        FilamentAccountingPlugin::make()->register($panel);
        $discovered = [];

        foreach (File::allFiles(__DIR__.'/../../src') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'FilamentAccounting\\'.str_replace(['/', '\\'], '\\', substr($file->getRelativePathname(), 0, -4));

            if (is_subclass_of($class, Resource::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $discovered[] = $class;
            }
        }

        $this->assertNotEmpty($discovered);
        $this->assertEqualsCanonicalizing($discovered, $panel->getResources(), 'Every concrete package resource needs a plugin feature registration.');
    }

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
