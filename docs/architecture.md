# Architecture

`fliix-cloud/filament-accounting` is the single Laravel/Filament product package.
A host installs it through Composer and registers only
`FilamentAccountingPlugin`. The framework-free `fliix-cloud/php-fints` library
is transitive and exposes only the `Fhp\` protocol namespace.

## Runtime boundary

```text
Host application
└── fliix-cloud/filament-accounting
    ├── FilamentAccountingServiceProvider
    ├── FilamentAccountingPlugin
    ├── one filament-accounting configuration
    ├── Accounting, documents, tax, audit and reconciliation modules
    └── Banking/FinTs product integration
        └── fliix-cloud/php-fints (Fhp\ protocol core, no framework integration)
```

`fliix-cloud/filament-accounting-fints` is not part of the runtime. There is no
public bank-feed driver registry, bridge listener/job chain, owner mapper, copied
FinTS transaction, or second Filament plugin.

## Internal modules

```text
src/
├── Audit/           hash-chain, anchors, evidence export and verification
├── Authorization/   host abilities and trusted authorization boundary
├── Banking/
│   ├── FinTs/       connections, SCA, sync, payments, mandates and I/O adapters
│   └── Services/    canonical bank import and ledger provisioning
├── Compliance/      product profiles; version 0.1 is Germany-first
├── Documents/       invoice intake, storage, categories and document services
├── EInvoicing/      structured invoice adapters
├── Export/          controlled accounting exports
├── Filament/        thin localized UI orchestration
├── Ledger/          balanced journal engine and period controls
├── Models/          canonical persisted product models
├── Ownership/       Legal Entity, actor and tenant-context boundaries
├── Reconciliation/  deterministic matching, learning and split validation
├── Tax/             explainable sales-tax suggestions
└── Services/        application workflows
```

## Domain flow

```text
FinTS client
  → account/balance/transaction sync services
  → UnifiedBankTransactionImporter
  → canonical BankStatementLine
  → append-only BankTransactionSourceVersion
  → deterministic suggestions / user-confirmed reconciliation
  → immutable balanced journal and settlements
```

`AccountingBankAccount` is the canonical account and receives its internal asset
account idempotently from `BankLedgerAccountProvisioner`. Bank connection,
availability, user activation, balances, and sync timestamps remain on the same
product record. Users do not choose ledger mappings.

`BankStatementLine` is the canonical bank transaction. Source identity is stable per Legal
Entity/account/source ID. A retry with identical evidence is a no-op. A material
bank change creates a source version; posted values are preserved and flagged
for review rather than overwritten.

## Ownership and security

`LegalEntity` is the only company/tenant boundary. Trusted resolvers determine
the current entity and actor. Request parameters never select tenancy. Queue jobs
carry scalar durable identities and activate tenancy before querying models.

PIN, user ID, dialog state, SCA payload, and payment state remain encrypted or
redacted. Endpoints are HTTPS-only by default and validated against private-host
and allow-list policy. Ambiguous payment submissions are not automatically
retried. SCA challenges are tenant/ability scoped, non-cacheable, `nosniff`, and
sandboxed for SVG content.

## Business boundaries

- `LedgerEngine` owns double-entry posting; posted entries are immutable and
  corrections use reversals.
- Documents store exact minor-unit amounts, commercial snapshots, attachments,
  and selected tax/accounting decisions.
- Tax rates are time-versioned. Germany-first recommendations consider seller
  country, customer country, business/private status, VAT ID, item type, date,
  and item tax class. Ambiguity requires confirmation.
- Purchase invoices start with upload/manual intake and require a user-confirmed
  business category. The category resolves the internal account.
- Reconciliation distinguishes a single direct/partial assignment from a split
  of at least two positions. Local learning is explainable and never auto-posts.

All money is persisted as integer minor units and processed via
`FilamentAccounting\Support\ExactMoney` / `brick/money`; floats are not business
amounts. E-invoice generation and validation remain PHP-only per
[ADR 0002](adr/0002-php-only-e-invoice-validation.md).

The single-package decision and development-time three-repository boundary are documented
in [ADR 0003](adr/0003-unified-accounting-package.md).
