<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_bank_accounts', function (Blueprint $table): void {
            $table->boolean('ledger_mapping_confirmed')->default(false)->after('ledger_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('ledger_mapping_confirmed');
        });
    }
};
