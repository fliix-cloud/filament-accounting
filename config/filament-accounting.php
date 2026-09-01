<?php

use FilamentAccounting\Audit\FilesystemAuditAnchorStore;
use FilamentAccounting\Authorization\DefaultAccountingAuthorizer;
use FilamentAccounting\Compliance\GenericComplianceProfile;
use FilamentAccounting\Compliance\Germany\GermanComplianceProfile;
use FilamentAccounting\Ownership\AuthenticatedUserAccountingActorResolver;
use FilamentAccounting\Ownership\ConfiguredLegalEntityResolver;
use FilamentAccounting\Ownership\NullAccountingTenancyContextActivator;

return [
    'database' => [
        'connection' => env('ACCOUNTING_DB_CONNECTION'),
    ],

    'storage' => [
        'disk' => env('ACCOUNTING_DISK', 'local'),
        'attachments_directory' => 'accounting/attachments',
    ],

    'money' => [
        'rounding_mode' => 'half_up',
        'line_rounding' => 'line',
    ],

    'features' => [
        'dashboard' => true,
        'customers' => true,
        'suppliers' => true,
        'catalog' => true,
        'sales_invoices' => true,
        'purchase_invoices' => true,
        'bank_reconciliation' => true,
        'journal' => true,
        'chart_of_accounts' => true,
        'tax_and_posting_rules' => true,
        'reports' => true,
        'settings' => true,
        'audit' => true,
    ],

    'ownership' => [
        'entity_resolver' => ConfiguredLegalEntityResolver::class,
        'required' => true,
        'legal_entity_id' => env('ACCOUNTING_LEGAL_ENTITY_ID'),
        'legal_entity_uuid' => env('ACCOUNTING_LEGAL_ENTITY_UUID'),
    ],

    'actor' => [
        'resolver' => AuthenticatedUserAccountingActorResolver::class,
    ],

    'tenancy' => [
        'context_activator' => NullAccountingTenancyContextActivator::class,
    ],

    'authorization' => [
        'authorizer' => DefaultAccountingAuthorizer::class,
        'abilities' => [
            'view' => 'accounting.view',
            'manage_parties' => 'accounting.manage-parties',
            'manage_catalog' => 'accounting.manage-catalog',
            'create_draft_invoices' => 'accounting.invoices.draft',
            'issue_invoices' => 'accounting.invoices.issue',
            'register_purchase_invoices' => 'accounting.invoices.register-purchase',
            'post_documents' => 'accounting.documents.post',
            'view_bank' => 'accounting.bank.view',
            'draft_reconciliation' => 'accounting.reconciliation.draft',
            'finalize_reconciliation' => 'accounting.reconciliation.finalize',
            'reverse_reconciliation' => 'accounting.reconciliation.reverse',
            'view_journal' => 'accounting.journal.view',
            'create_manual_journal_drafts' => 'accounting.journal.draft',
            'post_manual_journals' => 'accounting.journal.post',
            'manage_chart' => 'accounting.chart.manage',
            'close_periods' => 'accounting.periods.close',
            'reopen_periods' => 'accounting.periods.reopen',
            'view_audit' => 'accounting.audit.view',
            'manage_settings' => 'accounting.settings.manage',
        ],
    ],

    'compliance' => [
        'profiles' => [
            'generic' => GenericComplianceProfile::class,
            'DE' => GermanComplianceProfile::class,
        ],
        'default' => 'generic',
    ],

    'audit' => [
        'application_version' => env('ACCOUNTING_RELEASE_VERSION'),
        'application_commit' => env('ACCOUNTING_RELEASE_COMMIT'),
        'configuration_snapshot_id' => env('ACCOUNTING_CONFIGURATION_SNAPSHOT_ID'),
        'anchor' => [
            'store' => FilesystemAuditAnchorStore::class,
            'disk' => env('ACCOUNTING_AUDIT_ANCHOR_DISK', 'local'),
            'prefix' => env('ACCOUNTING_AUDIT_ANCHOR_PREFIX', 'accounting/audit-anchors'),
            'required' => env('ACCOUNTING_AUDIT_ANCHOR_REQUIRED', false),

            // This is an explicit host assertion. The package cannot infer object lock,
            // retention, versioning, or independent permissions from Laravel's API.
            'immutable_storage_attested' => env('ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED', false),
        ],
    ],

    'bank_feeds' => [
        'drivers' => [],
    ],

    'e_invoice' => [
        'default_profile' => 'en16931',
    ],
];
