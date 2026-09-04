<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_legal_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->char('country_code', 2);
            $table->string('tax_number')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->char('base_currency', 3);
            $table->string('locale', 16)->default('de_DE');
            $table->string('timezone', 64)->default('Europe/Berlin');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('accounting_basis', 32)->default('accrual');
            $table->string('vat_method', 32)->nullable();
            $table->string('compliance_profile_key', 32)->default('generic');
            $table->string('invoice_bank_name')->nullable();
            $table->string('invoice_iban', 34)->nullable();
            $table->string('invoice_bic', 11)->nullable();
            $table->unsignedSmallInteger('default_payment_terms_days')->default(14);
            $table->string('invoice_logo_path')->nullable();
            $table->string('invoice_template_key', 64)->default('default');
            $table->string('invoice_template_version', 32)->default('1');
            $table->string('state', 16)->default('active');
            $table->timestamps();
            $table->index('state');
        });

        Schema::create('accounting_parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('kind', 16)->default('organization');
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->string('legal_name');
            $table->string('display_name')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(14);
            $table->char('default_currency', 3)->nullable();
            $table->string('external_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['legal_entity_id', 'is_customer', 'is_active'], 'acct_party_customer_idx');
            $table->index(['legal_entity_id', 'is_supplier', 'is_active'], 'acct_party_supplier_idx');
        });

        Schema::create('accounting_party_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('accounting_parties')->cascadeOnDelete();
            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
        });

        Schema::create('accounting_party_tax_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('accounting_parties')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('number');
            $table->char('country_code', 2)->nullable();
            $table->timestamps();
            $table->index(['party_id', 'type']);
        });

        Schema::create('accounting_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('sku')->nullable();
            $table->string('type', 16)->default('service');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 16)->default('unit');
            $table->string('default_quantity', 32)->default('1');
            $table->bigInteger('default_unit_price_minor')->default(0);
            $table->char('currency', 3);
            $table->string('default_account_role', 32)->nullable();
            $table->string('default_tax_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'sku'], 'acct_catalog_sku_uidx');
        });

        Schema::create('accounting_ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16);
            $table->string('normal_balance', 8);
            $table->char('currency', 3)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('accounting_ledger_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code'], 'acct_ledger_code_uidx');
            $table->index(['legal_entity_id', 'type']);
        });

        Schema::create('accounting_account_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('role', 32);
            $table->foreignId('ledger_account_id')->constrained('accounting_ledger_accounts')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'role'], 'acct_account_role_uidx');
        });

        Schema::create('accounting_tax_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('direction', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code'], 'acct_tax_code_uidx');
        });

        Schema::create('accounting_tax_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tax_code_id')->constrained('accounting_tax_codes')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('rate_bp');
            $table->boolean('recoverable')->default(true);
            $table->string('category', 32)->nullable();
            $table->string('reason')->nullable();
            $table->json('export_mapping')->nullable();
            $table->timestamps();
            $table->index(['tax_code_id', 'valid_from']);
        });

        Schema::create('accounting_posting_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('label');
            $table->text('explanation')->nullable();
            $table->string('compliance_profile_key', 32)->default('generic');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['legal_entity_id', 'code'], 'acct_posting_rule_code_uidx');
        });

        Schema::create('accounting_posting_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('posting_rule_id')->constrained('accounting_posting_rules')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('direction', 16)->nullable();
            $table->boolean('requires_receipt')->default(false);
            $table->string('tax_code')->nullable();
            $table->json('account_mappings');
            $table->json('line_templates');
            $table->timestamps();
            $table->unique(['posting_rule_id', 'version'], 'acct_posting_rule_ver_uidx');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('state', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 191)->nullable();
            $table->string('closed_by_id', 64)->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopened_by_type', 191)->nullable();
            $table->string('reopened_by_id', 64)->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'fiscal_year', 'period_number'], 'acct_period_uidx');
            $table->index(['legal_entity_id', 'state', 'starts_on'], 'acct_period_state_idx');
        });

        Schema::create('accounting_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('document_type', 32);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('next_number')->default(1);
            $table->string('prefix')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'document_type', 'fiscal_year'], 'acct_doc_seq_unique');
        });

        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('direction', 16);
            $table->string('number')->nullable();
            $table->string('supplier_invoice_number')->nullable();
            $table->string('document_status', 16)->default('draft');
            $table->string('posting_status', 16)->default('unposted');
            $table->foreignId('party_id')->nullable()->constrained('accounting_parties')->restrictOnDelete();
            $table->json('party_snapshot')->nullable();
            $table->json('legal_entity_snapshot')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('receipt_date')->nullable();
            $table->date('supply_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->char('currency', 3);
            $table->string('exchange_rate', 32)->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->json('e_invoice_meta')->nullable();
            $table->foreignId('corrected_document_id')->nullable()->constrained('accounting_documents')->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 64)->nullable();
            $table->string('issued_by_type', 191)->nullable();
            $table->string('issued_by_id', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'type', 'number'], 'acct_doc_number_uidx');
            $table->unique(['legal_entity_id', 'idempotency_key'], 'acct_doc_idem_uidx');
            $table->index(['legal_entity_id', 'supplier_invoice_number'], 'acct_doc_supplier_no_idx');
            $table->index(['legal_entity_id', 'document_status', 'issue_date'], 'acct_doc_status_date_idx');
            $table->index(['legal_entity_id', 'posting_status'], 'acct_doc_posting_idx');
        });

        Schema::create('accounting_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('accounting_documents')->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('description');
            $table->string('quantity', 32)->default('1');
            $table->string('unit', 16)->nullable();
            $table->bigInteger('unit_price_minor');
            $table->string('discount', 64)->nullable();
            $table->bigInteger('net_minor');
            $table->string('tax_code')->nullable();
            $table->unsignedBigInteger('tax_rule_version_id')->nullable();
            $table->unsignedSmallInteger('tax_rate_bp')->default(0);
            $table->string('tax_category', 32)->nullable();
            $table->string('tax_reason')->nullable();
            $table->boolean('tax_recoverable')->nullable();
            $table->json('tax_export_mapping')->nullable();
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->string('account_role', 32)->nullable();
            $table->unsignedBigInteger('ledger_account_id')->nullable();
            $table->unsignedBigInteger('catalog_item_id')->nullable();
            $table->string('classification_code', 64)->nullable();
            $table->boolean('classification_confirmed')->default(false);
            $table->boolean('tax_confirmed')->default(false);
            $table->string('imported_tax_code', 32)->nullable();
            $table->date('service_from')->nullable();
            $table->date('service_to')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'position']);
        });

        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('sequence')->nullable();
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->date('posted_on');
            $table->string('status', 16)->default('draft');
            $table->string('source_type', 64);
            $table->string('source_id', 64)->nullable();
            $table->string('description')->nullable();
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->string('exchange_rate', 32)->nullable();
            $table->unsignedBigInteger('posting_rule_version_id')->nullable();
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->string('posted_by_type', 191)->nullable();
            $table->string('posted_by_id', 64)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['legal_entity_id', 'idempotency_key'], 'acct_journal_idem_uidx');
            $table->unique(['legal_entity_id', 'sequence'], 'acct_journal_seq_uidx');
            $table->index(['legal_entity_id', 'posted_on', 'status'], 'acct_journal_posted_idx');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('accounting_journal_entries')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('accounting_ledger_accounts')->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('base_debit_minor')->default(0);
            $table->bigInteger('base_credit_minor')->default(0);
            $table->string('description')->nullable();
            $table->string('tax_code')->nullable();
            $table->unsignedBigInteger('tax_rule_version_id')->nullable();
            $table->timestamps();
            $table->index(['ledger_account_id', 'journal_entry_id'], 'acct_jline_account_idx');
        });

        Schema::create('accounting_open_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('accounting_documents')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('accounting_parties')->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('currency', 3);
            $table->bigInteger('original_minor');
            $table->date('due_on')->nullable();
            $table->boolean('is_reversed')->default(false);
            $table->timestamps();
            $table->unique('document_id');
            $table->index(['legal_entity_id', 'party_id', 'due_on'], 'acct_open_item_due_idx');
        });

        Schema::create('accounting_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->foreignId('open_item_id')->constrained('accounting_open_items')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained('accounting_journal_entries')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->boolean('is_reversed')->default(false);
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->timestamps();
            $table->index(['open_item_id', 'is_reversed']);
        });

        Schema::create('accounting_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->string('attachable_type', 191);
            $table->unsignedBigInteger('attachable_id');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('disk');
            $table->string('path');
            $table->string('source_type', 32)->default('upload');
            $table->longText('structured_payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('uploaded_by_type', 191)->nullable();
            $table->string('uploaded_by_id', 64)->nullable();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id'], 'acct_attach_morph_idx');
            $table->index(['legal_entity_id', 'sha256'], 'acct_attach_hash_idx');
            $table->unique(
                ['legal_entity_id', 'attachable_type', 'attachable_id', 'sha256', 'source_type'],
                'acct_attach_idempotent_uidx',
            );
        });

        Schema::create('accounting_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('legal_entity_id')->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unsignedSmallInteger('event_schema_version')->default(1);
            $table->unsignedSmallInteger('canonicalization_version')->default(1);
            $table->string('hash_algorithm', 32)->default('sha256');
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('impersonator_type', 191)->nullable();
            $table->string('impersonator_id', 64)->nullable();
            $table->string('operation', 64);
            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->longText('canonical_payload');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->string('correlation_id', 64)->nullable();
            $table->string('causation_id', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('application_version', 64)->nullable();
            $table->string('application_commit', 64)->nullable();
            $table->string('configuration_snapshot_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('technical_at');
            $table->timestamps();
            $table->unique(['legal_entity_id', 'sequence'], 'acct_audit_entity_seq_uidx');
            $table->unique('event_hash', 'acct_audit_event_hash_uidx');
            $table->index(['legal_entity_id', 'occurred_at'], 'acct_audit_time_idx');
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('accounting_audit_chain_heads', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->primary()->constrained('accounting_legal_entities')->restrictOnDelete();
            $table->unsignedBigInteger('last_sequence');
            $table->char('last_event_hash', 64);
            $table->unsignedBigInteger('event_count');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        $tables = [
            'accounting_audit_chain_heads',
            'accounting_audit_events',
            'accounting_attachments',
            'accounting_settlements',
            'accounting_open_items',
            'accounting_journal_lines',
            'accounting_journal_entries',
            'accounting_document_lines',
            'accounting_documents',
            'accounting_document_sequences',
            'accounting_periods',
            'accounting_posting_rule_versions',
            'accounting_posting_rules',
            'accounting_tax_rule_versions',
            'accounting_tax_codes',
            'accounting_account_role_assignments',
            'accounting_ledger_accounts',
            'accounting_catalog_items',
            'accounting_party_tax_ids',
            'accounting_party_addresses',
            'accounting_parties',
            'accounting_legal_entities',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
