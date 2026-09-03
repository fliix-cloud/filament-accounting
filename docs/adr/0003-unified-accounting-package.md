# ADR 0003: Unified Filament Accounting package

- Status: Accepted
- Date: 2026-09-03
- Target version: 0.1

## Context

The product combines accounting, invoicing, banking, tax, reconciliation,
ownership, audit, and Filament workflows. All persisted product state requires
one consistent tenancy boundary and one canonical model per business concept.
The FinTS protocol implementation has an independent maintenance and provenance
boundary because it is framework-free and consumed directly from
`nemiah/phpFinTS`.

## Decision

`fliix-cloud/filament-accounting` is the only product package installed and
configured by a host. It exposes one Laravel provider,
`FilamentAccountingServiceProvider`, one Filament plugin,
`FilamentAccountingPlugin`, one configuration namespace, and one trusted
`LegalEntity` tenancy boundary.

The product is a modular monolith. FinTS application integration lives below
`FilamentAccounting\Banking\FinTs`; ledger, documents, tax, reconciliation,
ownership, audit, and Filament remain explicit internal modules. Business rules
are implemented in services rather than Filament callbacks.

The framework-independent protocol package is `nemiah/php-fints` with the
public namespace `Fhp\`. It is a direct upstream dependency and contains no
Laravel or Filament integration. This project maintains no protocol fork.

`AccountingBankAccount` is the canonical product bank account and
`BankStatementLine` is the canonical bank transaction. FinTS synchronization
imports directly through `UnifiedBankTransactionImporter`. Every materially
different source state creates an append-only
`BankTransactionSourceVersion`; posted accounting values are never silently
rewritten.

`LegalEntity` directly owns bank connections, accounts, transactions, payments,
mandates, documents, and journals. Queue jobs carry scalar identifiers and
activate trusted tenancy before querying models. `DirectDebitMandate` is the
authoritative mandate entity and references `PartyBankAccount`.

The UI groups functionality by business workflow. Purchase invoices require a
business category that deterministically resolves the internal account. Tax
rates remain versioned. Reconciliation distinguishes direct assignments from
true splits and stores explainable learning rules only after confirmation.

## Consequences

- Hosts install and register one product package and one plugin.
- Protocol and framework releases remain independently reviewable; protocol
  changes are contributed upstream only after the documented evidence gate.
- Version 0.1 starts from one final fresh-install schema expressed by create-only
  migrations.
- Development databases are recreated after schema changes.
- Storage immutability, access control, backups, retention, monitoring, and
  procedural documentation remain host responsibilities.
- The software makes no unconditional statement of GoBD certification.

## Development reset

The project is pre-release and has no external installations. After schema
changes, recreate the development database with:

```bash
php artisan migrate:fresh --seed
php artisan filament-accounting:verify
```

Package migrations describe only the complete target schema.
