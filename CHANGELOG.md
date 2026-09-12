# Changelog

## 0.1.0 — 2026-09-12

First taggable release baseline: one installable accounting package (Laravel
provider + Filament plugin) with a first-party ledger, German-first sales and
purchase workflows, FinTS banking, reconciliation, and integrity controls, on
SQLite or MySQL/MariaDB. GoBD release gates are NOT all closed; see
[`docs/gobd.md`](docs/gobd.md) for the scoped claim boundary and the remaining
operator evidence.

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
- Nine migrations: three table-creation migrations and six forward alterations
  for reconciliation tax rules, party contacts, catalog pricing/EAN, invoice
  versions, payment/layout snapshots, and requested FinTS sync ranges.
- Feature-controlled registration of ledger accounts, posting rules, and audit
  events, with fluent chart, tax/posting, and audit switches.
- Registration coverage tests for every concrete package resource and each
  feature's resources, pages, and widgets.
- Explicit development schema and first-0.x release policy in `docs/upgrading.md`.

### Changed

- Grouped Filament navigation by business workflow, including all banking and
  FinTS administration below the Banking section.
- Switched the framework-independent protocol dependency directly to
  `nemiah/php-fints`; no product-owned protocol fork is required.
- Preserve host panel width by default; `fullWidth()` opts into the previous
  layout. Document the two custom accounting colors used for bank amounts.
- Tax codes follow `tax_and_posting_rules` alongside posting rules, independently
  of `settings`; bank balances follow `bank_reconciliation`.
- Remove the unused `reports` config entry. The reports navigation group remains
  populated by independently enabled journal, chart, and audit resources.
- Move catalog implementation prompts to `development/specifications`; keep
  integrator instructions in `docs/catalog-import-export.md`.
- Installation grants development stability only to the explicitly required
  packages instead of lowering the host's global minimum stability.

### Fixed

- JSON and streaming accounting datasets use a consistent database snapshot even
  when the MySQL host uses `READ COMMITTED`. Preserve the host session isolation
  default and reject unsupported snapshot drivers. Protect the post-anchor audit
  refresh with an independent snapshot and entity lock.
- MySQL regressions cover concurrent master-data commits, payments waiting during
  export, the next export including the committed payment, and rollback without
  leaking export isolation into later host transactions.

- Security hardening from PR #18 and its follow-up: authenticated and scoped
  audit exports, deny-by-default FinTS resource access, protected institute sync
  and reconciliation actions, endpoint validation, constrained FinTS payload
  deserialization, and payment/SCA transactions on the accounting connection.
- Journal CSV formula escaping, invoice read authorization, rich-text script
  removal, immutable used-account identity, and exact UBL tax-percent parsing.
  See `docs/security-audit-2026-09-11-findings.md` for S-1 through S-16; S-12,
  S-14, and S-15 remain accepted, and GoBD findings remain a separate open track.
- Payment- and reconciliation-connection rollback regressions: money and SCA
  claims land exclusively on the configured accounting connection, never the
  default connection, so a separate `ACCOUNTING_DB_CONNECTION` cannot split a
  claim's commit and rollback. See `docs/gobd.md` (F9/S-8).
- ZUGFeRD (CII) parse coverage and an exact tax-rate to basis-point conversion,
  replacing a float path already abandoned in the UBL parser (F7).
- E-invoice line-level allowances/charges: importers prefer the parsed per-line
  net (UBL `LineExtensionAmount`, ZUGFeRD line summation) over quantity × price,
  so a discounted or surcharged line posts its source net and passes source-total
  reconciliation instead of being rejected (F7).
- Bank sync catch-up drains a long gap oldest-first in `max_range_days` chunks;
  each successful chunk advances the coverage frontier, and the marker clears
  once the final chunk reaches today, instead of re-fetching the newest window
  forever (F12).

### Added

- An opt-in MySQL fresh-install baseline test (`MySqlInstallBaselineTest`) run
  from the MySQL CI job: it proves every migration, the German profile boot,
  chart provisioning, a bank account, and a posted sales invoice work on the
  supported engine, not only on SQLite. It also drains a 200-day sync gap
  oldest-first in three 90-day chunks with idempotent imports and a clearing
  coverage marker, passing locally on MySQL 9.7.0.
- Service-level authorization Gates on `TransferService`
  (`create_bank_transfer`), `DirectDebitService` (`create_bank_direct_debit`),
  and `SuggestReconciliationMatches` (`draft_reconciliation`), with a
  PaymentAuthorization regression test (F3).
- Rounding boundary tests (quantity × price, discount, and tax half-up at the
  cent) and an end-to-end fractional-quantity posting that proves the balanced
  journal to rounded amounts (F6).
