<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_document_lines', function (Blueprint $table): void {
            $table->string('tax_category', 32)->nullable()->after('tax_rate_bp');
            $table->string('tax_reason')->nullable()->after('tax_category');
            $table->boolean('tax_recoverable')->nullable()->after('tax_reason');
            $table->json('tax_export_mapping')->nullable()->after('tax_recoverable');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_document_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_category',
                'tax_reason',
                'tax_recoverable',
                'tax_export_mapping',
            ]);
        });
    }
};
