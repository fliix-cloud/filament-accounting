<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_attachments', function (Blueprint $table): void {
            $table->unique(
                ['legal_entity_id', 'attachable_type', 'attachable_id', 'sha256', 'source_type'],
                'acct_attach_idempotent_uidx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('accounting_attachments', function (Blueprint $table): void {
            $table->dropUnique('acct_attach_idempotent_uidx');
        });
    }
};
