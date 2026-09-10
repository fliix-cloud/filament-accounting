<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_catalog_items', function (Blueprint $table) {
            $table->string('ean')->nullable();
            $table->bigInteger('purchase_price_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_catalog_items', function (Blueprint $table) {
            $table->dropColumn(['ean', 'purchase_price_minor']);
        });
    }
};
