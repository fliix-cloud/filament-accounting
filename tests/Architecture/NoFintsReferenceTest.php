<?php

namespace FilamentAccounting\Tests\Architecture;

use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class NoFintsReferenceTest extends TestCase
{
    #[Test]
    public function src_does_not_reference_filament_fints(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src'));
        $hits = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname()) ?: '';
            if (preg_match('/FilamentFints|filament-fints|fliix-cloud\/filament-fints/', $contents) === 1) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'Accounting src must not reference FilamentFints.');
    }
}
