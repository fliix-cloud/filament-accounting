<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->unsignedInteger('invoice_version')->default(1);
            $table->dropUnique('acct_doc_number_uidx');
            $table->unique(['legal_entity_id', 'type', 'number', 'invoice_version'], 'acct_doc_number_version_uidx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('accounting_documents')->where('invoice_version', '>', 1)->exists()) {
            throw new RuntimeException('Invoice versions exist. Restore a pre-upgrade backup instead of discarding invoice history.');
        }

        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->dropUnique('acct_doc_number_version_uidx');
            $table->dropColumn('invoice_version');
            $table->unique(['legal_entity_id', 'type', 'number'], 'acct_doc_number_uidx');
        });
    }
};
