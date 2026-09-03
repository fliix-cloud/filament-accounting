<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fints_bank_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('display_name');
            $table->string('bank_code', 16);
            $table->string('endpoint_url');
            $table->text('username');
            $table->text('pin');
            $table->text('customer_id')->nullable();
            $table->string('tan_mode_id')->nullable();
            $table->string('tan_mode_name')->nullable();
            $table->string('tan_medium_name')->nullable();
            $table->longText('encrypted_fints_state')->nullable();
            $table->timestamp('fints_state_saved_at')->nullable();
            $table->json('tan_modes_cache')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('last_successful_connection_at')->nullable();
            $table->timestamp('last_account_sync_at')->nullable();
            $table->timestamp('last_transaction_sync_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 64)->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'status'], 'fints_conn_entity_status_idx');
        });

        Schema::create('fints_institutes', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_code', 16)->unique();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('checksum_method', 8)->nullable();
            $table->string('hbci_host')->nullable();
            $table->string('pin_tan_url')->nullable();
            $table->string('hbci_version', 16)->nullable();
            $table->string('pin_tan_version', 16)->nullable();
            $table->boolean('has_pin_tan')->default(false);
            $table->string('source', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['has_pin_tan', 'name'], 'fints_institute_available_idx');
        });

        Schema::create('accounting_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_connection_id')->nullable()->constrained('fints_bank_connections')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('accounting_ledger_accounts')->restrictOnDelete();
            $table->string('display_name');
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->char('currency', 3);
            $table->string('source', 16)->default('fints');
            $table->string('external_account_id', 128);
            $table->string('fingerprint', 128)->nullable();
            $table->string('account_number', 32)->nullable();
            $table->string('sub_account', 32)->nullable();
            $table->string('bank_code', 16)->nullable();
            $table->string('product_name')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->bigInteger('booked_balance_minor')->nullable();
            $table->bigInteger('pending_balance_minor')->nullable();
            $table->bigInteger('credit_line_minor')->nullable();
            $table->bigInteger('available_amount_minor')->nullable();
            $table->timestamp('balance_at')->nullable();
            $table->timestamp('last_balance_sync_at')->nullable();
            $table->timestamp('last_transaction_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'source', 'external_account_id'], 'acct_bank_acct_ext_uidx');
            $table->unique(['bank_connection_id', 'fingerprint'], 'acct_bank_acct_fingerprint_uidx');
        });

        Schema::create('accounting_bank_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('accounting_bank_accounts')->nullOnDelete();
            $table->string('source', 16)->default('fints');
            $table->unsignedInteger('upserted_count')->default(0);
            $table->string('cursor')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('accounting_bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounting_bank_accounts')->restrictOnDelete();
            $table->string('source', 16)->default('fints');
            $table->string('external_id', 128);
            $table->string('source_account_external_id', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('booking_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('source_status', 16)->default('booked');
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban', 34)->nullable();
            $table->string('counterparty_account')->nullable();
            $table->text('purpose')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('payment_reference')->nullable();
            $table->json('source_payload')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('first_imported_at')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->json('review_reason')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'source', 'external_id'], 'acct_stmt_ext_uidx');
            $table->index(['bank_account_id', 'booking_date'], 'acct_stmt_booking_idx');
            $table->index(['legal_entity_id', 'source_status', 'booking_date'], 'acct_stmt_status_idx');
        });

        Schema::create('fints_direct_debit_creditor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('name');
            $table->string('creditor_identifier', 35);
            $table->string('creditor_identifier_normalized', 35);
            $table->string('street')->nullable();
            $table->string('building_number', 32)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['legal_entity_id', 'creditor_identifier_normalized'], 'fints_dd_creditor_entity_identifier_uidx');
        });

        Schema::create('fints_direct_debit_mandates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('accounting_parties')->restrictOnDelete();
            $table->foreignId('party_bank_account_id')->constrained('accounting_party_bank_accounts')->restrictOnDelete();
            $table->foreignId('creditor_profile_id')->constrained('fints_direct_debit_creditor_profiles')->restrictOnDelete();
            $table->string('reference', 35);
            $table->string('reference_normalized', 35);
            $table->string('scheme', 8)->default('CORE');
            $table->string('mandate_type', 16)->default('one_off');
            $table->string('debtor_name');
            $table->string('debtor_iban', 34);
            $table->string('debtor_bic', 11)->nullable();
            $table->string('debtor_street')->nullable();
            $table->string('debtor_building_number', 32)->nullable();
            $table->string('debtor_postal_code', 32)->nullable();
            $table->string('debtor_city')->nullable();
            $table->string('debtor_country', 2)->nullable();
            $table->date('signed_on');
            $table->string('status', 16)->default('active');
            $table->timestamp('debtor_bank_confirmed_at')->nullable();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'reference_normalized'], 'fints_dd_mandate_entity_reference_uidx');
            $table->index(['creditor_profile_id', 'status'], 'fints_dd_mandate_profile_status_idx');
        });

        Schema::create('fints_bank_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_connection_id')->constrained('fints_bank_connections')->restrictOnDelete();
            $table->foreignId('accounting_bank_account_id')->constrained('accounting_bank_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('recipient_name');
            $table->string('recipient_iban', 34);
            $table->string('recipient_bic', 11)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('EUR');
            $table->string('purpose')->nullable();
            $table->date('requested_execution_date')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('type', 32)->default('sepa');
            $table->string('status', 48)->default('draft');
            $table->text('bank_status_text')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('initiated_by_type', 191)->nullable();
            $table->string('initiated_by_id', 64)->nullable();
            $table->timestamps();

            $table->index(['accounting_bank_account_id', 'status'], 'fints_transfer_account_status_idx');
        });

        Schema::create('fints_bank_direct_debits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_connection_id')->constrained('fints_bank_connections')->restrictOnDelete();
            $table->foreignId('accounting_bank_account_id')->constrained('accounting_bank_accounts')->restrictOnDelete();
            $table->foreignId('creditor_profile_id')->constrained('fints_direct_debit_creditor_profiles')->restrictOnDelete();
            $table->foreignId('direct_debit_mandate_id')->constrained('fints_direct_debit_mandates')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('sepa_message_id', 35)->nullable();
            $table->string('payment_information_id', 35)->nullable();
            $table->string('creditor_name');
            $table->string('creditor_identifier')->nullable();
            $table->string('creditor_street')->nullable();
            $table->string('creditor_building_number', 32)->nullable();
            $table->string('creditor_postal_code', 32)->nullable();
            $table->string('creditor_city')->nullable();
            $table->string('creditor_country', 2)->nullable();
            $table->string('debtor_name');
            $table->string('debtor_iban', 34);
            $table->string('debtor_bic', 11)->nullable();
            $table->string('debtor_street')->nullable();
            $table->string('debtor_building_number', 32)->nullable();
            $table->string('debtor_postal_code', 32)->nullable();
            $table->string('debtor_city')->nullable();
            $table->string('debtor_country', 2)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('EUR');
            $table->string('purpose')->nullable();
            $table->string('mandate_id');
            $table->date('mandate_signed_on');
            $table->string('sequence_type', 8)->default('OOFF');
            $table->string('scheme', 8)->default('CORE');
            $table->date('requested_collection_date')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('status', 48)->default('draft');
            $table->text('bank_status_text')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('initiated_by_type', 191)->nullable();
            $table->string('initiated_by_id', 64)->nullable();
            $table->timestamps();

            $table->index(['accounting_bank_account_id', 'status'], 'fints_debit_account_status_idx');
        });

        Schema::create('fints_sca_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_connection_id')->constrained('fints_bank_connections')->cascadeOnDelete();
            $table->string('operation_type', 48);
            $table->string('related_type', 191)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('state', 48);
            $table->longText('encrypted_fints_state')->nullable();
            $table->longText('encrypted_action')->nullable();
            $table->longText('encrypted_challenge_text')->nullable();
            $table->longText('encrypted_challenge_payload')->nullable();
            $table->string('challenge_type', 32)->nullable();
            $table->string('challenge_mime')->nullable();
            $table->string('tan_medium_name')->nullable();
            $table->string('vop_match', 32)->nullable();
            $table->text('vop_information')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('first_poll_at')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->unsignedInteger('max_poll_attempts')->nullable();
            $table->unsignedInteger('poll_interval_seconds')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('return_url')->nullable();
            $table->text('last_status_message')->nullable();
            $table->string('confirmed_by_type', 191)->nullable();
            $table->string('confirmed_by_id', 64)->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'state'], 'fints_sca_entity_state_idx');
        });

        Schema::create('fints_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_connection_id')->constrained('fints_bank_connections')->cascadeOnDelete();
            $table->foreignId('accounting_bank_account_id')->nullable()->constrained('accounting_bank_accounts')->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'created_at'], 'fints_sync_entity_created_idx');
        });

        Schema::create('accounting_bank_transaction_source_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')
                ->constrained('accounting_legal_entities', indexName: 'acct_bank_source_entity_fk')
                ->restrictOnDelete();
            $table->foreignId('bank_transaction_id')
                ->constrained('accounting_bank_statement_lines', indexName: 'acct_bank_source_transaction_fk')
                ->restrictOnDelete();
            $table->foreignId('import_run_id')
                ->nullable()
                ->constrained('accounting_bank_import_runs', indexName: 'acct_bank_source_import_run_fk')
                ->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('source_id', 128);
            $table->string('source_fingerprint', 128);
            $table->string('source_status', 16);
            $table->json('normalized_payload');
            $table->longText('raw_payload')->nullable();
            $table->char('source_hash', 64);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['bank_transaction_id', 'version'], 'acct_bank_source_version_uidx');
            $table->index(['legal_entity_id', 'source_fingerprint'], 'acct_bank_source_fingerprint_idx');
        });

        Schema::create('accounting_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('statement_line_id')->constrained('accounting_bank_statement_lines')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('match_meta')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'idempotency_key'], 'acct_recon_idem_uidx');
            $table->index(['statement_line_id', 'status']);
        });

        Schema::create('accounting_reconciliation_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('accounting_reconciliations')->restrictOnDelete();
            $table->string('purpose', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('open_item_id')->nullable();
            $table->unsignedBigInteger('posting_rule_version_id')->nullable();
            $table->unsignedBigInteger('ledger_account_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('open_item_id');
        });

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
        $tables = [
            'accounting_reconciliation_learning_rules',
            'accounting_reconciliation_splits',
            'accounting_reconciliations',
            'accounting_bank_transaction_source_versions',
            'fints_sync_runs',
            'fints_sca_sessions',
            'fints_bank_direct_debits',
            'fints_bank_transfers',
            'fints_direct_debit_mandates',
            'fints_direct_debit_creditor_profiles',
            'accounting_bank_statement_lines',
            'accounting_bank_import_runs',
            'accounting_bank_accounts',
            'fints_institutes',
            'fints_bank_connections',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
