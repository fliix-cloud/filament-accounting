# GoBD control matrix

**Version:** 0.1.0

**Status:** Working baseline for internal implementation; legal and audit review is outstanding.

**Scope:** `fliix-cloud/filament-accounting` is the accounting system of record. `filament-fints`, `filament-accounting-fints`, and the host environment are separate trust domains.

This matrix turns the master plan into uniquely identifiable controls. “Implemented” means that the repository contains the named technical mechanism and automated test; it does not mean that a deployment is GoBD-compliant or independently audited.

## Scope and non-goals

- Included: invoices and original evidence, journals and open items, tax/posting rule versions, bank reconciliation, audit evidence, retention metadata, and Z1/Z2/Z3 access for one legal entity at a time.
- Country profile: German controls extend the generic international core. They must not silently change generic behavior.
- Explicitly excluded: fixed-asset accounting, cash-register/TSE/DSFinV-K functionality, and a blanket claim of “GoBD certification.”
- The host must satisfy the versioned [host responsibility contract](compliance/HOST_RESPONSIBILITY_CONTRACT.md). Package controls cannot compensate for missing access control, immutable backup, time synchronization, or operational evidence.

## Control status legend

| Status | Meaning |
| --- | --- |
| Implemented | Mechanism and focused automated evidence exist in this repository. |
| Partial | A useful mechanism exists, but the control objective is not yet fully met. |
| Planned | No sufficient implementation evidence exists yet. |
| Host | The deploying organization must implement and evidence the control. |

## Product controls

| Control ID | Objective / GoBD principle | Owner and implementation location | Test and retained evidence | Status | Residual risk / next action |
| --- | --- | --- | --- | --- | --- |
| ACC-SCP-01 | Keep the audited product scope, trust boundaries, country profile, and exclusions explicit. | Product owner; [ADR 0001](adr/0001-gobd-responsibility-and-trust-boundaries.md), this matrix, compliance profiles. | Reviewed ADR, tagged matrix, release scope. | Partial | Reference deployment and external auditor approval remain open. |
| ACC-AUD-01 | Make critical actions sequential, tamper-evident, attributable, and chronologically verifiable per legal entity. | Engineering; `AuditLogger`, shared event/anchor validators, `FilesystemAuditAnchorStore`, `CreateAuditAnchor`, `AuditEvidenceExporter`, audit schema. | `AuditLedgerTest`, `AuditAnchorTest`; anchor/verify/export/offline-verify JSON commands; database chain head, external anchor objects, and portable evidence document. | Partial | The package creates and verifies chained external anchors online/offline, but the reference host must evidence object lock/versioning, independently obtained anchor trust, separate permissions, schedule/monitoring, backup, and restore. |
| ACC-AUD-02 | Prevent ordinary application paths from changing or deleting recorded audit evidence. | Engineering; `AuditEvent` immutable model guard and read-only Filament resource. | Model mutation/deletion tests. | Partial | Query Builder, direct SQL, migrations, and database administrators can bypass ORM guards; verification and host database roles must cover this. |
| ACC-IMM-01 | Preserve posted journals, issued/received documents, their lines, and corrections without in-place mutation. | Ledger/document owners; immutable model guards and reversal services. | Ledger and invoice-flow tests; reversal references. | Partial | Reconciliation, settlement, rule-version, period-transition, and database-level hardening remain incomplete. |
| ACC-EVD-01 | Retain byte-identical original evidence with put-once semantics, capability checks, integrity verification, and restore. | Evidence-storage owner; future `EvidenceStorage` contract. | Manipulation, missing-object, export, and restore tests. | Planned | Current attachment hashes do not prevent replacement or deletion in storage. |
| ACC-DOC-01 | Preserve invoice, party, renderer, template, terms, language, and rule snapshots at issuance/receipt. | Document owner; document snapshot and correction workflow. | Reference invoice corpus and deterministic rendering evidence. | Planned | Current master data and renderer changes may prevent exact later reproduction. |
| ACC-EINV-01 | Preserve and validate ZUGFeRD/XRechnung originals and validation provenance. | E-invoice owner; adapters, evidence storage, validation reports. | Valid/invalid EN-16931 corpus and byte-integrity checks. | Planned | Parser success alone is not a conformity validation. |
| ACC-BNK-01 | Preserve every imported bank source state and make reconciliation/split/settlement changes append-only or reversal-based. | Banking/reconciliation owner. | Pending→booked, reversal, split, retry, and control-total tests. | Planned | Current source and reconciliation records still contain in-place update paths. |
| ACC-TIM-01 | Distinguish occurrence, document, receipt, capture, posting, lock, correction, and technical times. | Compliance-profile and period owners. | Late-capture and reopen/close chronology reports. | Planned | Technical timestamps alone do not evidence timely capture. |
| ACC-EXP-01 | Provide read-only Z1, reproducible Z2, and deterministic complete Z3 access with relationships and checksums. | Export/audit-workspace owner. | Synthetic reference tenant, deterministic export and round-trip verification. | Planned | The current generic journal CSV is not a complete Z3 package. |
| ACC-RET-01 | Apply versioned retention classification, legal holds, controlled disposal, and disposal evidence. | Product plus host records owner. | Legal-hold and disposal authorization tests; disposal certificate. | Planned | Host-only deletion and model cascades are not an executable retention contract. |
| ACC-VER-01 | Detect integrity, sequence, relation, amount, tenant, retention, and export faults with human and machine-readable output. | Verification owner; online and offline verification commands. | Intentional manipulation suite, schema-versioned JSON output, portable audit-evidence round trip, and CI artifacts. | Partial | Online/offline audit-chain and anchor verification exists; document/storage hashes, relationships, periods, retention, and full Z3 exports remain open. |
| ACC-IKS-01 | Enforce and evidence least privilege, separation of duties, four-eyes controls, and access recertification. | Host security owner plus package authorization owner. | Role matrix, access reviews, approval events, exception reports. | Host | Package abilities are not sufficient evidence of operational segregation. |
| ACC-DOCS-01 | Keep general, user, system, operating, IKS, data-model, access, backup, import, change, export, and retention documentation aligned with releases. | Product and host documentation owners. | Tagged documentation manifest and release review. | Planned | Existing documents do not yet constitute complete procedural documentation. |

## Cross-repository interface controls

| Control ID | Objective | Owner / evidence | Status | Residual risk / next action |
| --- | --- | --- | --- | --- |
| FINTS-SRC-01 | Store exact, versioned bank source states without float-based business amounts. | `filament-fints`; source-version and pending→booked contract tests. | Planned | Implement in a separate repository PR. |
| FINTS-SYNC-01 | Retain immutable sync-run scope, snapshot hash, counts, currency/direction totals, and terminal status. | `filament-fints`; replayable synthetic sync evidence. | Planned | Implement in a separate repository PR. |
| BRIDGE-PRV-01 | Transfer source/version/run/hash/mapper/entity/account/amount/correlation provenance. | `filament-accounting-fints`; versioned contract fixtures. | Planned | Implement only after both endpoint contracts are released. |
| BRIDGE-EO-01 | Achieve idempotent effect with recoverable outbox/replay and end-to-end control totals. | Bridge owner; crash/retry/dead-letter tests and import acknowledgements. | Planned | Current normalized payload hash is not the full provenance envelope. |

## Host and governance controls

| Control ID | Objective | Evidence owner | Status | Residual risk / next action |
| --- | --- | --- | --- | --- |
| HOST-IAM-01 | MFA, personal privileged accounts, least privilege, emergency-access logging, periodic access review. | Deploying organization. | Host | Provide identity-provider policy and review evidence. |
| HOST-DB-01 | Separate application, migration, backup, and administration roles; deny normal application update/delete rights on immutable evidence where supported. | Deploying organization. | Host | Define the reference database roles and test direct-SQL controls. |
| HOST-BCK-01 | Encrypted separate backup including database, originals, anchors, keys, and documentation; quarterly restore test. | Deploying organization. | Host | Select RPO/RTO and immutable/offline backup mechanism. |
| HOST-TIM-01 | Synchronize and monitor UTC system time while retaining the legal entity’s business timezone. | Deploying organization. | Host | Store NTP monitoring and incident evidence. |
| HOST-REL-01 | Protected branches, reviewed changes, required CI, verifiable tags, SBOM, migration/config manifest, and immutable release artifacts. | Repository/release owner. | Partial | Repository rulesets and the reproducible release manifest are not yet evidenced here. |
| HOST-MON-01 | Alert on failed integrity checks, missing evidence, sequence gaps, import/replay faults, privileged actions, and backup/restore failures. | Deploying organization. | Host | Connect verification exit codes to monitored incident handling. |

## Proposed first audit reference stack

The initial target is intentionally narrow and remains subject to Phase 0 approval: PHP 8.4, Laravel 13, Filament 5, PostgreSQL 18, a queue worker and scheduler under supervised operation, private S3-compatible evidence storage with versioning and object lock, and the German compliance profile. Exact patch versions, operating system, storage vendor, key service, backup topology, and package versions belong in the certification release manifest rather than this living matrix.

## Evidence update rule

Every compliance-relevant pull request must update the affected row’s implementation location, automated test, evidence artifact, status, and residual risk. A control may only move to “Implemented” when all named evidence is reproducible in the frozen reference stack.
