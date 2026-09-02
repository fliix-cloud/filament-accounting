<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->json('legal_entity_snapshot')->nullable()->after('party_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->dropColumn('legal_entity_snapshot');
        });
    }
};
