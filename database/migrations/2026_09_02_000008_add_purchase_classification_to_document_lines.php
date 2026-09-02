<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_document_lines', function (Blueprint $table): void {
            $table->string('classification_code', 64)->nullable()->after('catalog_item_id');
            $table->boolean('classification_confirmed')->default(false)->after('classification_code');
            $table->boolean('tax_confirmed')->default(false)->after('classification_confirmed');
            $table->string('imported_tax_code', 32)->nullable()->after('tax_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_document_lines', function (Blueprint $table): void {
            $table->dropColumn(['classification_code', 'classification_confirmed', 'tax_confirmed', 'imported_tax_code']);
        });
    }
};
