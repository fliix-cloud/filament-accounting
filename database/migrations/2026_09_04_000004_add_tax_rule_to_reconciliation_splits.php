<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_reconciliation_splits', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_rule_version_id')->nullable()->after('ledger_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_reconciliation_splits', function (Blueprint $table): void {
            $table->dropColumn('tax_rule_version_id');
        });
    }
};
