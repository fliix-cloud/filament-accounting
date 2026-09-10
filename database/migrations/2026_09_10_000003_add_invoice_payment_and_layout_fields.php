<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_legal_entities', function (Blueprint $table): void {
            $table->string('invoice_subtitle')->nullable();
            $table->string('invoice_contact_name')->nullable();
        });
        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->string('payment_method')->nullable();
            $table->foreignId('direct_debit_mandate_id')->nullable()->constrained('fints_direct_debit_mandates')->restrictOnDelete();
            $table->json('payment_snapshot')->nullable();
        });
        Schema::table('accounting_document_lines', function (Blueprint $table): void {
            $table->string('catalog_sku')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('accounting_documents')->whereNotNull('payment_snapshot')->exists()) {
            throw new RuntimeException('Invoice payment snapshots exist. Restore a pre-upgrade backup instead of discarding invoice evidence.');
        }

        Schema::table('accounting_document_lines', fn (Blueprint $table) => $table->dropColumn('catalog_sku'));
        Schema::table('accounting_documents', function (Blueprint $table): void {
            $table->dropForeign(['direct_debit_mandate_id']);
            $table->dropColumn(['payment_method', 'direct_debit_mandate_id', 'payment_snapshot']);
        });
        Schema::table('accounting_legal_entities', fn (Blueprint $table) => $table->dropColumn(['invoice_subtitle', 'invoice_contact_name']));
    }
};
