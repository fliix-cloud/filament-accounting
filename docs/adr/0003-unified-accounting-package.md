# ADR 0003: Unified Filament Accounting package

- Status: Accepted
- Date: 2026-09-03
- Target version: 0.1
- Supersedes: the active three-product-package boundary described in older planning documents

## Context

Accounting and FinTS were previously exposed through three installable
Laravel/Filament packages. Both endpoint packages had bank-account and
transaction concepts, while `filament-accounting-fints` copied state through a
driver/bridge layer. Hosts had to compose two plugins, multiple providers and
configuration files, an owner mapper, and a runtime bridge even though the
product always uses accounting and FinTS together.

The FinTS protocol implementation itself has a different maintenance boundary.
It is framework-independent, has upstream provenance in `nemiah/phpFinTS`, and
contains five permanently maintained local protocol patches.

## Decision

`fliix-cloud/filament-accounting` is the only product package installed and
configured by a host. It exposes exactly one Laravel provider,
`FilamentAccountingServiceProvider`, one Filament plugin,
`FilamentAccountingPlugin`, one configuration namespace, and one trusted
`LegalEntity` tenancy boundary.

The product is a modular monolith. FinTS application integration lives below
`FilamentAccounting\Banking\FinTs`; ledger, documents, tax, reconciliation,
ownership, audit, and Filament remain explicit internal modules. Business rules
are implemented in services and not in Filament callbacks.

The framework-free protocol fork is published as `fliix-cloud/php-fints` and is
a transitive dependency. Its public namespace remains `Fhp\`. The Accounting
repository has no `Fhp\` source tree or root autoload mapping.

There is one canonical product bank account (`AccountingBankAccount`) and one
canonical product transaction (`BankStatementLine`, presented as “Umsatz”).
FinTS synchronization imports directly through
`UnifiedBankTransactionImporter`. Every materially different bank source state
creates an append-only `BankTransactionSourceVersion`; posted accounting values
are never silently rewritten.

`LegalEntity` directly owns bank connections, accounts, transactions, payments,
mandates, documents, and journals. Queue jobs carry scalar IDs and activate the
trusted tenancy context before model queries. `DirectDebitMandate` is the
authoritative mandate entity and references `PartyBankAccount`.

The normal UI hides chart-of-account selection, debit/credit mechanics,
mapping confirmations, and editable posting templates. The purchase workflow
uses a required business category to resolve internal accounts. Tax rates remain
versioned. Reconciliation keeps direct assignments distinct from real splits
and stores explainable learning rules only after confirmation; learning never
auto-posts in version 0.1.

## Consequences

- Hosts install and register one product package and one plugin.
- The bridge, owner mapper, source-link registry, driver registry, copied FinTS
  transaction model, and mirrored bank-account workflow disappear from runtime.
- Protocol and framework releases stay independently reviewable.
- The protocol repository must be renamed/published before a stable dependency
  can replace the temporary commit-pinned Composer package declaration.
- Existing installations require a dry-run consolidation. Legacy tables are
  retained, marked outside the active write path, and never dropped by the
  consolidation command.
- No software-only statement of GoBD certification is made. Storage immutability,
  access control, backups, retention, monitoring, and procedural documentation
  remain host responsibilities.

## Migration

1. Back up the database and evidence storage.
2. Deploy the unified package and run its migrations.
3. Run `php artisan filament-accounting:consolidate-legacy --dry-run --json`.
4. Resolve every reported ownership or mandate mapping blocker explicitly.
5. Run `php artisan filament-accounting:consolidate-legacy --json`.
6. Run `php artisan filament-accounting:verify --json` and retain both reports.
7. Remove the old provider/plugin/bridge registrations only after application
   verification. Do not delete legacy tables during this release.

Detailed mappings and rollback guidance are in [UPGRADE.md](../../UPGRADE.md).

## Alternatives considered

- Keep three product packages: rejected because mirrored state and runtime
  composition create avoidable operational and consistency risk.
- Copy the `Fhp\` core into Accounting: rejected because it obscures provenance,
  licensing, protocol review, and upstream synchronization.
- Use unmodified `nemiah/php-fints`: rejected because the five tested local
  protocol deltas are required by current bank workflows.
- Retain a public bank-provider driver platform: rejected for version 0.1; FinTS
  is the only supported path and internal I/O interfaces already provide test seams.

## Reversal strategy

Before final cutover, rollback means redeploying the previous package set and
restoring the pre-migration database snapshot. After the unified writer is in
use, do not point the old bridge at the same data: it would reintroduce two
writers. A later architectural reversal requires a new ADR, an explicit export
contract, and tested replay from canonical source versions. Retained legacy
tables are evidence and migration fallback inputs, not an active rollback writer.
