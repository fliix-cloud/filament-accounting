<?php

namespace FilamentAccounting\Tests\Database;

use FilamentAccounting\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class PartyContactColumnsMigrationTest extends TestCase
{
    #[Test]
    public function it_upgrades_the_legacy_party_schema_with_contact_columns(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('accounting_parties', 'invoice_email'));
        $this->assertFalse(Schema::hasColumn('accounting_party_addresses', 'address_role'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('accounting_parties', 'invoice_email'));
        $this->assertTrue(Schema::hasColumn('accounting_party_addresses', 'address_role'));
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_09_04_000005_add_party_contact_columns.php';
    }
}
