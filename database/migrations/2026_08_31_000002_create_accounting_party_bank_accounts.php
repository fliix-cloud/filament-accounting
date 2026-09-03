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
            $table->timestamps();

            $table->unique(['party_id', 'iban'], 'acct_party_bank_iban_uidx');
            $table->index(['legal_entity_id', 'party_id'], 'acct_party_bank_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_party_bank_accounts');
    }
};
