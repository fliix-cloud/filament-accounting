<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_legal_entities', function (Blueprint $table): void {
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('invoice_bank_name')->nullable();
            $table->string('invoice_iban', 34)->nullable();
            $table->string('invoice_bic', 11)->nullable();
            $table->unsignedSmallInteger('default_payment_terms_days')->default(14);
            $table->string('invoice_logo_path')->nullable();
            $table->string('invoice_template_key', 64)->default('default');
            $table->string('invoice_template_version', 32)->default('1');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_legal_entities', function (Blueprint $table): void {
            $table->dropColumn([
                'address_line1',
                'address_line2',
                'postal_code',
                'city',
                'region',
                'tax_number',
                'vat_id',
                'email',
                'phone',
                'website',
                'invoice_bank_name',
                'invoice_iban',
                'invoice_bic',
                'default_payment_terms_days',
                'invoice_logo_path',
                'invoice_template_key',
                'invoice_template_version',
            ]);
        });
    }
};
