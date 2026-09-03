<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reconciliation_learning_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('direction', 16);
            $table->string('match_type', 24);
            $table->string('match_value', 255);
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('target_label');
            $table->unsignedInteger('confirmed_count')->default(1);
            $table->timestamp('last_confirmed_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['legal_entity_id', 'direction', 'match_type', 'match_value', 'target_type', 'target_id'],
                'acct_recon_learning_unique',
            );
            $table->index(
                ['legal_entity_id', 'direction', 'is_active'],
                'acct_recon_learning_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reconciliation_learning_rules');
    }
};
