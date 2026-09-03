# ADR 0001: GoBD responsibility and trust boundaries

- Status: Accepted as implementation baseline; external legal/audit validation pending
- Date: 2026-09-01
- Decision owners: Product, accounting engineering, host operations
- Related controls: ACC-SCP-01, ACC-AUD-01, HOST-DB-01, HOST-REL-01

## Context

`filament-accounting` is a Composer package, not a complete operating
organization. GoBD control objectives span product code, database and object
storage, deployment configuration, identities, backups, monitoring, change
management, and user procedures. A hash chain inside the application database
provides tamper evidence but cannot by itself protect against an administrator
who can rewrite both the chain and its head.

## Decision

1. `filament-accounting` is the system of record for documents, journals, open
   items, banking state, reconciliation results, audit evidence, retention
   state, and audit access.
2. `nemiah/php-fints` is the framework-independent upstream protocol dependency. It
   owns protocol parsing and message construction but no Laravel, Filament,
   tenancy, persistence, accounting, or product behavior.
3. The host owns identity and access management, database roles, evidence-store
   immutability, keys, time synchronization, backup and restore, monitoring,
   incident response, and deployed release/configuration identity.
4. German rules are an explicit compliance profile layered on a generic
   international accounting core.
5. Fixed-asset accounting and cash-register/TSE/DSFinV-K functionality are
   outside the current scope.
6. Product language must describe a tested version and scope and must not claim
   unconditional GoBD certification.
7. Audit evidence is append-only at the application layer and hash-chained per
   Legal Entity. A separately controlled external anchor is required before the
   audit control is complete.

## Trust boundaries

- Application services request authorized business changes through the public
  product workflows.
- The normal application database role does not have unrestricted migration or
  administration capabilities.
- ORM guards are defense in depth; privileged database activity remains in the
  threat model.
- Evidence storage and the external chain-anchor writer use permissions that
  are independent from normal database operation.
- Operators, deployment automation, package releases, and configuration are
  identifiable inputs to accounting processing and are retained without secrets.
- Protocol input is untrusted until validated, normalized, tenant-scoped, and
  recorded as source evidence.

## Consequences

- Every critical state change emits attributable audit evidence in the same
  database transaction as the business change.
- Corrections use reversal or a new version; finalized truth is not edited in
  place.
- The technical test suite cannot establish operating compliance by itself;
  host evidence and complementary user controls remain mandatory.
- The proposed reference stack and responsibility allocation require external
  tax and audit review before a compliance claim is made.
