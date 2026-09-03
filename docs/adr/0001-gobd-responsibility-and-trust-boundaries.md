# ADR 0001: GoBD responsibility and trust boundaries

> Historical baseline: the three-package boundary in this ADR was superseded on
> 2026-09-03 by [ADR 0003](0003-unified-accounting-package.md). Its accounting
> and host-control principles remain relevant; its active FinTS/bridge package
> allocation does not.

- Status: Accepted as implementation baseline; external legal/audit validation pending
- Date: 2026-09-01
- Decision owners: Product, accounting engineering, host operations
- Related controls: ACC-SCP-01, ACC-AUD-01, HOST-DB-01, HOST-REL-01

## Context

`filament-accounting` is a Composer package, not a complete operating organization. GoBD control objectives span product code, the two FinTS repositories, database and object-storage capabilities, deployment configuration, identities, backups, monitoring, change management, and user procedures. A hash chain inside the same database is useful tamper evidence but is not protection against an attacker who can rewrite both the event chain and its head.

## Decision

1. `filament-accounting` is the system of record for documents, journals, open items, reconciliation results, audit evidence, retention state, and audit access. Its accounting truth must survive removal or outage of FinTS integrations.
2. `filament-fints` is a bank source/subledger boundary. It owns FinTS communication, source snapshots and versions, SCA state, and sync evidence; it does not own ledger, tax, or invoice truth.
3. `filament-accounting-fints` is an anti-corruption bridge. It owns versioned transformation provenance, idempotent delivery/recovery, acknowledgements, and cross-system control totals; it owns neither accounting rules nor FinTS protocol rules.
4. The host owns identity and access management, database roles, evidence-storage immutability, keys, time synchronization, backup/restore, monitoring, incident response, and the deployed release/configuration identity. These duties are enumerated in the versioned host responsibility contract.
5. German rules are an explicit compliance profile layered on a generic international accounting core. Country-specific deadlines, retention classifications, e-invoice validation, and audit exports must not be implied for the generic profile.
6. Fixed-asset accounting and cash-register/TSE/DSFinV-K functionality are outside the current scope. Adding a cash-sales or cash-register workflow requires a new scope decision.
7. Product language must describe a tested version and scope. It must not claim an official or unconditional “GoBD certification.”
8. Audit evidence is append-only at the application layer and hash-chained per legal entity. A separately controlled external anchor is required before ACC-AUD-01 is complete.

## Trust boundaries

- Application services are trusted to request valid business changes through authorized paths.
- The normal application database role is not trusted with unrestricted migration/administration capabilities.
- ORM guards are defense in depth; direct SQL and privileged database activity remain in the threat model.
- Evidence storage and the external chain-anchor writer must be independently permissioned from normal database operation.
- Operators, deployment automation, package releases, and configuration are identifiable inputs to accounting processing and must be retained without secrets.
- Cross-repository messages are untrusted until provenance, version compatibility, idempotency, and control totals are verified.

## Consequences

- Every critical state change must emit an attributable audit event in the same database transaction as the business change.
- Historical corrections use reversal, correction, or a new version; finalized truth is not edited in place.
- Cross-repository work ships as separate, version-compatible releases and PRs.
- The technical test suite cannot by itself establish operating compliance; host evidence and complementary user controls remain mandatory.
- Phase 0 must validate the proposed reference stack and responsibility allocation with tax counsel and the intended PS 880 auditor.
