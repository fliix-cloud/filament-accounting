# ADR 0003: Unified Filament Accounting package

- Status: Accepted
- Date: 2026-09-03
- Target version: 0.1
- Supersedes: the active three-product-package boundary described in older planning documents

## Context

During development, Accounting and FinTS were split across three repositories.
Both endpoint packages had bank-account and transaction concepts, while
`filament-accounting-fints` copied state through a driver/bridge layer. This
design was never released or installed outside the development demo. The target
product always uses accounting and FinTS together, so the split was removed
before version 0.1.

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
- Version 0.1 starts from one final, fresh-install schema. Development databases
  are recreated; no data conversion or compatibility path is part of the product.
- No software-only statement of GoBD certification is made. Storage immutability,
  access control, backups, retention, monitoring, and procedural documentation
  remain host responsibilities.

## Development reset

The project is pre-release and has no external installations. After schema
changes, recreate the development database with `php artisan migrate:fresh
--seed`, then run `php artisan filament-accounting:verify`. Package migrations
describe only the target schema and do not alter or convert earlier prototypes.

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

Before version 0.1, an architectural reversal is a source-code decision followed
by another fresh development database. After a public release, any data upgrade
or architectural reversal requires a new ADR and a separately reviewed schema
transition; none is implemented pre-emptively in the initial release.
