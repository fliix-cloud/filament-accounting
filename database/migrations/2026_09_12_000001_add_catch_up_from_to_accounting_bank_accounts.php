<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_bank_accounts', function (Blueprint $table): void {
            $table->date('catch_up_from')->nullable()->after('last_transaction_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('catch_up_from');
        });
    }
};
