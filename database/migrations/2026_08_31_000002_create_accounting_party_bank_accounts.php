<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_party_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('accounting_parties')->cascadeOnDelete();
            $table->string('holder_name')->nullable();
            $table->string('iban', 34);
            $table->string('bic', 11)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('mandate_reference', 35)->nullable();
            $table->string('mandate_reference_normalized', 35)->nullable();
            $table->date('mandate_signed_on')->nullable();
            $table->string('mandate_scheme', 8)->nullable();
            $table->string('mandate_type', 16)->nullable();
            $table->string('mandate_status', 16)->nullable();
            $table->uuid('external_mandate_id')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'iban'], 'acct_party_bank_iban_uidx');
            $table->unique(['legal_entity_id', 'mandate_reference_normalized'], 'acct_party_bank_mref_uidx');
            $table->index(['legal_entity_id', 'party_id'], 'acct_party_bank_entity_idx');
            $table->index('external_mandate_id', 'acct_party_bank_ext_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_party_bank_accounts');
    }
};
