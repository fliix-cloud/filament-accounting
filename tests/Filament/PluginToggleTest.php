<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\FilamentAccountingPlugin;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PluginToggleTest extends TestCase
{
    #[Test]
    public function resource_registration_follows_plugin_toggles(): void
    {
        $enabled = FilamentAccountingPlugin::make();
        $this->assertTrue($enabled->hasCustomers());
        $this->assertTrue($enabled->hasJournal());

        $disabled = FilamentAccountingPlugin::make()->customers(false)->journal(false);
        $this->assertFalse($disabled->hasCustomers());
        $this->assertFalse($disabled->hasJournal());

        $this->assertSame(CustomerResource::class, CustomerResource::class);
        $this->assertSame(JournalEntryResource::class, JournalEntryResource::class);
    }
}
