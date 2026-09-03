# Changelog

## Unreleased

### Added

- One installable Accounting product package with a Laravel provider, a
  Filament plugin, and a trusted Legal Entity boundary.
- Integrated FinTS connections, canonical bank accounts and transactions,
  source-version evidence, SCA, transfers, direct debits, and mandates.
- Direct, partial, and multi-target reconciliation with explainable local
  learning rules that never post automatically.
- German-first sales and purchase workflows, versioned tax rules, deterministic
  expense categories, e-invoice artifacts, and exact minor-unit money handling.
- Append-only audit evidence, external anchor support, integrity verification,
  controlled exports, and host-responsibility documentation.
- Three create-only migrations describing the complete fresh-install schema.

### Changed

- Grouped Filament navigation by business workflow, including all banking and
  FinTS administration below the Banking section.
- Switched the framework-independent protocol dependency directly to
  `nemiah/php-fints`; no product-owned protocol fork is required.
