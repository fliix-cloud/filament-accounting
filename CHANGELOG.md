# Changelog

## Unreleased

### Changed

- Unified Accounting, FinTS application integration, and reconciliation in one
  installable package with one provider, one plugin, one configuration, and one
  Legal Entity boundary.
- Replaced copied bridge transactions with direct, idempotent FinTS imports into
  the canonical bank transaction and append-only source versions.
- Made the Accounting bank account canonical and provisioned its internal ledger
  account automatically.
- Made direct-debit mandates authoritative and directly related to parties and
  party bank accounts; used mandate identity is immutable.
- Simplified the German-first UI to business workflows and hid ledger mapping,
  debit/credit mechanics, and editable posting templates from normal navigation.
- Added required purchase expense categories with deterministic internal posting
  and explainable DE/EU/non-EU sales-tax suggestions.
- Added post-confirmation local reconciliation learning rules that remain
  explainable, editable/deletable, tenant scoped, and non-auto-posting.

### Added

- Integrated FinTS connections, accounts, balances, transactions, SCA, SEPA
  transfers, direct debits, mandates, commands, views, routes, and translations.
- A fresh-install target schema expressed by three create-only migrations.
- ADR 0003, protocol patch inventory, and unified GoBD documents.

### Removed

- Runtime dependencies on `fliix-cloud/filament-fints` and
  `fliix-cloud/filament-accounting-fints` as Laravel/Filament product packages.
- Public bank-feed/source-link registries, bridge driver configuration, owner
  mapping, mirrored sync jobs, and the second Filament integration.
