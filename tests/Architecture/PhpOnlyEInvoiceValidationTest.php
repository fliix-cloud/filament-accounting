<?php

namespace FilamentAccounting\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PhpOnlyEInvoiceValidationTest extends TestCase
{
    #[Test]
    public function executable_project_files_do_not_invoke_java_backed_invoice_validators(): void
    {
        $forbidden = '/Zugferd(?:Kosit|Pdf)Validator|java\s+-jar|\.jar\b|JAVA_HOME/i';
        $root = dirname(__DIR__, 2);
        $files = [$root.'/composer.json'];

        foreach (['src', 'config', 'scripts', '.github/workflows'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression($forbidden, $contents, $file);
        }
    }
}
