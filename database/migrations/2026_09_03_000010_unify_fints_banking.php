<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createConnections();
        $this->createInstitutes();
        $this->extendAccountingBankAccounts();
        $this->createCreditorProfiles();
        $this->createMandates();
        $this->createTransfers();
        $this->createDirectDebits();
        $this->createScaSessions();
        $this->createSyncRuns();
        $this->createSourceVersions();
        $this->createLegacyConsolidationRuns();
    }

    public function down(): void
    {
        // Deliberately non-destructive: these tables can predate this package
        // and may still be required for a verified legacy consolidation.
        Schema::dropIfExists('accounting_bank_transaction_source_versions');
    }

    private function createConnections(): void
    {
        if (! Schema::hasTable('fints_bank_connections')) {
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

            return;
        }

        $this->addMissing('fints_bank_connections', 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
        $this->addMissing('fints_bank_connections', 'encrypted_fints_state', fn (Blueprint $table) => $table->longText('encrypted_fints_state')->nullable());
        $this->addMissing('fints_bank_connections', 'fints_state_saved_at', fn (Blueprint $table) => $table->timestamp('fints_state_saved_at')->nullable());
    }

    private function createInstitutes(): void
    {
        if (Schema::hasTable('fints_institutes')) {
            return;
        }

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
    }

    private function extendAccountingBankAccounts(): void
    {
        $columns = [
            'bank_connection_id' => fn (Blueprint $table) => $table->unsignedBigInteger('bank_connection_id')->nullable(),
            'legacy_fints_bank_account_id' => fn (Blueprint $table) => $table->unsignedBigInteger('legacy_fints_bank_account_id')->nullable()->unique(),
            'fingerprint' => fn (Blueprint $table) => $table->string('fingerprint', 128)->nullable(),
            'account_number' => fn (Blueprint $table) => $table->string('account_number', 32)->nullable(),
            'sub_account' => fn (Blueprint $table) => $table->string('sub_account', 32)->nullable(),
            'bank_code' => fn (Blueprint $table) => $table->string('bank_code', 16)->nullable(),
            'product_name' => fn (Blueprint $table) => $table->string('product_name')->nullable(),
            'account_holder_name' => fn (Blueprint $table) => $table->string('account_holder_name')->nullable(),
            'is_available' => fn (Blueprint $table) => $table->boolean('is_available')->default(true),
            'is_enabled' => fn (Blueprint $table) => $table->boolean('is_enabled')->default(true),
            'booked_balance_minor' => fn (Blueprint $table) => $table->bigInteger('booked_balance_minor')->nullable(),
            'pending_balance_minor' => fn (Blueprint $table) => $table->bigInteger('pending_balance_minor')->nullable(),
            'credit_line_minor' => fn (Blueprint $table) => $table->bigInteger('credit_line_minor')->nullable(),
            'available_amount_minor' => fn (Blueprint $table) => $table->bigInteger('available_amount_minor')->nullable(),
            'balance_at' => fn (Blueprint $table) => $table->timestamp('balance_at')->nullable(),
            'last_balance_sync_at' => fn (Blueprint $table) => $table->timestamp('last_balance_sync_at')->nullable(),
            'last_transaction_sync_at' => fn (Blueprint $table) => $table->timestamp('last_transaction_sync_at')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            $this->addMissing('accounting_bank_accounts', $name, $definition);
        }
    }

    private function createCreditorProfiles(): void
    {
        if (! Schema::hasTable('fints_direct_debit_creditor_profiles')) {
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

            return;
        }

        $this->addMissing('fints_direct_debit_creditor_profiles', 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
    }

    private function createMandates(): void
    {
        if (! Schema::hasTable('fints_direct_debit_mandates')) {
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

            return;
        }

        $this->addMissing('fints_direct_debit_mandates', 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
        $this->addMissing('fints_direct_debit_mandates', 'party_id', fn (Blueprint $table) => $table->unsignedBigInteger('party_id')->nullable());
        $this->addMissing('fints_direct_debit_mandates', 'party_bank_account_id', fn (Blueprint $table) => $table->unsignedBigInteger('party_bank_account_id')->nullable());
    }

    private function createTransfers(): void
    {
        if (! Schema::hasTable('fints_bank_transfers')) {
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

            return;
        }

        $this->addPaymentColumns('fints_bank_transfers');
        $this->relaxLegacyPaymentColumns('fints_bank_transfers');
    }

    private function createDirectDebits(): void
    {
        if (! Schema::hasTable('fints_bank_direct_debits')) {
            Schema::create('fints_bank_direct_debits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
                $table->foreignId('bank_connection_id')->constrained('fints_bank_connections')->restrictOnDelete();
                $table->foreignId('accounting_bank_account_id')->constrained('accounting_bank_accounts')->restrictOnDelete();
                $table->foreignId('creditor_profile_id')->nullable()->constrained('fints_direct_debit_creditor_profiles')->nullOnDelete();
                $table->foreignId('direct_debit_mandate_id')->nullable()->constrained('fints_direct_debit_mandates')->nullOnDelete();
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

            return;
        }

        $this->addPaymentColumns('fints_bank_direct_debits');
        $this->relaxLegacyPaymentColumns('fints_bank_direct_debits');
        $this->addMissing('fints_bank_direct_debits', 'creditor_profile_id', fn (Blueprint $table) => $table->unsignedBigInteger('creditor_profile_id')->nullable());
        $this->addMissing('fints_bank_direct_debits', 'direct_debit_mandate_id', fn (Blueprint $table) => $table->unsignedBigInteger('direct_debit_mandate_id')->nullable());
    }

    private function createScaSessions(): void
    {
        if (! Schema::hasTable('fints_sca_sessions')) {
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

            return;
        }

        $this->addMissing('fints_sca_sessions', 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
    }

    private function createSyncRuns(): void
    {
        if (! Schema::hasTable('fints_sync_runs')) {
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

            return;
        }

        $this->addMissing('fints_sync_runs', 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
        $this->addMissing('fints_sync_runs', 'accounting_bank_account_id', fn (Blueprint $table) => $table->unsignedBigInteger('accounting_bank_account_id')->nullable());
    }

    private function createSourceVersions(): void
    {
        if (Schema::hasTable('accounting_bank_transaction_source_versions')) {
            return;
        }

        Schema::create('accounting_bank_transaction_source_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('bank_transaction_id')->constrained('accounting_bank_statement_lines')->restrictOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained('accounting_bank_import_runs')->nullOnDelete();
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
    }

    private function createLegacyConsolidationRuns(): void
    {
        if (Schema::hasTable('accounting_legacy_consolidation_runs')) {
            return;
        }

        Schema::create('accounting_legacy_consolidation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 64)->default('three-package-fints');
            $table->string('status', 32);
            $table->char('evidence_hash', 64);
            $table->json('report');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'completed_at'], 'acct_legacy_source_completed_idx');
        });
    }

    private function addPaymentColumns(string $tableName): void
    {
        $this->addMissing($tableName, 'legal_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('legal_entity_id')->nullable());
        $this->addMissing($tableName, 'accounting_bank_account_id', fn (Blueprint $table) => $table->unsignedBigInteger('accounting_bank_account_id')->nullable());
        $this->addMissing($tableName, 'amount_minor', fn (Blueprint $table) => $table->bigInteger('amount_minor')->nullable());
    }

    private function relaxLegacyPaymentColumns(string $tableName): void
    {
        if (Schema::hasColumn($tableName, 'bank_account_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('bank_account_id')->nullable()->change();
            });
        }

        if (Schema::hasColumn($tableName, 'amount')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('amount', 18, 2)->nullable()->change();
            });
        }
    }

    private function addMissing(string $tableName, string $column, callable $definition): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};
