<?php

namespace FilamentAccounting\Tests\Banking;

use FilamentAccounting\Contracts\BankSourceLinkGenerator;
use FilamentAccounting\Models\BankStatementLine;
use FilamentAccounting\Support\BankSourceLinkRegistry;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BankSourceLinkRegistryTest extends TestCase
{
    #[Test]
    public function source_links_are_resolved_explicitly_by_driver_key(): void
    {
        $line = new BankStatementLine;
        $line->driver_key = 'test-bank';

        $registry = new BankSourceLinkRegistry;
        $this->assertNull($registry->url($line));

        $registry->register(new class implements BankSourceLinkGenerator
        {
            public function driverKey(): string
            {
                return 'test-bank';
            }

            public function url(BankStatementLine $line): ?string
            {
                return '/source/'.$line->driver_key;
            }
        });

        $this->assertSame('/source/test-bank', $registry->url($line));
    }
}
