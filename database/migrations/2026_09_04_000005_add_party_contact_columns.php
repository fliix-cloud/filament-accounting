<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_parties', function (Blueprint $table): void {
            $table->string('invoice_email')->nullable()->after('email');
        });

        Schema::table('accounting_party_addresses', function (Blueprint $table): void {
            $table->string('address_role', 16)->default('both')->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_party_addresses', function (Blueprint $table): void {
            $table->dropColumn('address_role');
        });

        Schema::table('accounting_parties', function (Blueprint $table): void {
            $table->dropColumn('invoice_email');
        });
    }
};
