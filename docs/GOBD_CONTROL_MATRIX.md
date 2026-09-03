# GoBD control matrix

**Version:** 0.1 unified architecture
**Status:** Technical working baseline; legal and independent audit review outstanding

**Scope:** `fliix-cloud/filament-accounting` is the system of record and contains
the accounting, FinTS application, reconciliation, document, tax, ownership, and
audit modules. `nemiah/php-fints` is the framework-free upstream protocol dependency.
See [ADR 0003](adr/0003-unified-accounting-package.md).

“Implemented” means the repository contains the named mechanism and automated
evidence. It does not mean a deployment is GoBD-compliant or certified.

## Status legend

| Status | Meaning |
| --- | --- |
| Implemented | Named technical mechanism and focused automated test exist. |
| Partial | Useful mechanism exists; the full control objective still needs work or host evidence. |
| Planned | Sufficient implementation evidence does not yet exist. |
| Host | Deploying organization must implement and evidence it. |

## Product controls

| ID | Objective | Implementation / evidence | Status | Residual risk / next action |
| --- | --- | --- | --- | --- |
| ACC-SCP-01 | Keep scope, architecture, Germany-first profile, exclusions, and trust boundaries explicit. | ADRs 0001/0003, architecture, master plan, host responsibility contract. | Partial | External legal/audit approval and frozen reference deployment remain open. |
| ACC-OWN-01 | Enforce one trusted Legal Entity boundary across every module. | `LegalEntityScope`, configured resolver, actor resolver, tenancy activator, `LegalEntityBankScope`; Resource/service/FinTS integration isolation tests. | Implemented | Host must bind trusted context and abilities correctly. |
| ACC-AUD-01 | Make critical actions sequential, attributable, tamper-evident, and chronologically verifiable per Legal Entity. | `AuditLogger`, event/anchor validators, `FilesystemAuditAnchorStore`, anchor/export/verify commands; `AuditLedgerTest`, `AuditAnchorTest`. | Partial | Independently controlled immutable anchor storage, monitoring, backup and restore are host evidence. |
| ACC-AUD-02 | Prevent ordinary application mutation/deletion of audit evidence. | `AuditEvent` model guards and non-default/read-only technical Resource; mutation tests. | Partial | Direct SQL/DBA/migration paths require database roles and independent verification. |
| ACC-IMM-01 | Preserve posted journals and commercial documents; correct through reversal. | Immutable model guards, ledger/document/reconciliation reversal services; ledger, invoice and reconciliation tests. | Partial | Database-level enforcement and broader period-transition evidence remain. |
| ACC-EVD-01 | Retain original document bytes and verify content identity. | Configured attachment storage, SHA-256 metadata, safe download and tenant checks; `AttachmentStorageTest`. | Partial | Host must attest put-once/object lock, versioning, retention, backup and restore. |
| ACC-DOC-01 | Preserve commercial, party, tax/account and artifact decisions for invoices. | Document/line snapshots, original attachments, issue/register services; invoice/e-invoice tests. | Partial | Exact renderer/template reproduction and full conformance corpus remain. |
| ACC-TAX-01 | Select versioned rates by date and require confirmation for ambiguous tax cases. | `TaxCode`/`TaxRuleVersion`, overlap/immutability guards, German seed profile, `SalesTaxSuggestionService`; tax tests. | Implemented | Recommendations are limited to documented Germany-first cases, not universal tax advice. |
| ACC-BNK-01 | Store one canonical transaction and every material bank source state without floats. | `UnifiedBankTransactionImporter`, `BankTransactionSourceVersion`, exact minor units; pending→booked/storno/change/retry tests. | Implemented | Available raw payload depends on what the bank/protocol response exposes. |
| ACC-BNK-02 | Avoid duplicate account/transaction truth and provision the internal bank ledger mapping automatically. | Canonical `AccountingBankAccount`/`BankStatementLine`, direct `TransactionSyncService`, `BankLedgerAccountProvisioner`; unified FinTS tests. | Implemented | The initial release schema contains only the canonical tables. |
| ACC-REC-01 | Finalize direct, partial and real split reconciliation exactly, idempotently and by reversal. | Locked `FinalizeReconciliation`, split validation, settlement/journal links; reconciliation tests. | Implemented | Operational concurrency characteristics still depend on the selected database. |
| ACC-REC-02 | Make local suggestions explainable and user-confirmed. | Deterministic matcher, scored reasons, `ReconciliationLearningRule`, post-confirmation storage, edit/deactivate/delete UI; matcher tests. | Implemented | No automatic posting from learning is allowed in 0.1. |
| ACC-VER-01 | Detect journal and audit/anchor integrity failures with machine-readable evidence. | `filament-accounting:verify --json`, online/offline audit evidence verification, manipulation tests. | Partial | Complete document/storage/relation/retention/Z3 verification remains open. |
| ACC-EXP-01 | Provide read-only Z1, reproducible Z2, and deterministic complete Z3 access. | Current read views and generic journal export. | Planned | Generic journal CSV is not a complete relational Z3 package. |
| ACC-RET-01 | Apply versioned retention, legal holds, controlled disposal, and disposal evidence. | Operations documentation and host contract. | Planned | Executable retention lifecycle is not complete. |
| ACC-IKS-01 | Evidence least privilege, separation of duties, four-eyes controls and access recertification. | Package abilities plus host IAM process. | Host | Package abilities alone are not operational segregation evidence. |
| ACC-DOCS-01 | Keep architecture, operation, data model and controls aligned with a release. | ADR 0003, README, architecture, master plan, matrix, upstream policy. | Partial | Complete organization-specific procedural documentation remains a host duty. |

## Protocol and development-boundary controls

| ID | Objective | Implementation / evidence | Status | Residual risk / next action |
| --- | --- | --- | --- | --- |
| FINTS-BND-01 | Keep protocol parsing separate from Laravel/Filament/product behavior. | Direct `nemiah/php-fints` dependency, `Fhp\` namespace, Composer architecture test. | Implemented | Verify the selected upstream revision in every release gate. |
| FINTS-UP-01 | Contribute only demonstrated, bank-neutral protocol corrections upstream. | [Upstream contribution policy](upstream/php-fints-upstream-policy.md): normative source, minimal fixture, failing upstream test, narrow fix and full regression gates. | Partial | The observed CAMT status behavior is a candidate only; no change is submitted until every evidence item is satisfied. |

## Host and governance controls

| ID | Objective | Evidence owner | Status | Required evidence |
| --- | --- | --- | --- | --- |
| HOST-IAM-01 | MFA, personal privileged accounts, least privilege, emergency access, reviews. | Deploying organization | Host | Identity policy, role mapping, approval and review records. |
| HOST-DB-01 | Separate application/migration/backup/admin roles and constrain immutable records. | Deploying organization | Host | Database grants, migration procedure and direct-SQL control tests. |
| HOST-STO-01 | Immutable/versioned originals and audit anchors with separate permissions. | Deploying organization | Host | Object-lock/versioning configuration, retention policy and access evidence. |
| HOST-BCK-01 | Encrypted independent backup and tested restore of data, objects, anchors, keys and docs. | Deploying organization | Host | RPO/RTO, backup logs and periodic restore reports. |
| HOST-TIM-01 | Synchronize/monitor UTC time while retaining business timezone. | Deploying organization | Host | NTP configuration, monitoring and incident records. |
| HOST-REL-01 | Reviewed changes, protected branches, required CI, verifiable tags, SBOM and immutable releases. | Repository/release owner | Partial | Repository rules and reproducible signed release manifest remain to be evidenced. |
| HOST-MON-01 | Alert and respond to integrity, evidence, sync, queue, SCA and backup failures. | Deploying organization | Host | Alert routing, runbooks, incidents and periodic exercises. |

## Reference and update rule

The proposed initial reference stack is PHP 8.4, Laravel 13, Filament 5,
PostgreSQL 18, supervised workers/scheduler, private S3-compatible storage with
versioning/object lock, and the Germany profile. Exact patch versions and vendors
belong in a frozen release manifest.

Every compliance-relevant PR must update affected rows, tests, evidence, status,
and residual risk. A control moves to “Implemented” only when its named evidence
is reproducible; host obligations never become implemented merely because an
application option exists.
