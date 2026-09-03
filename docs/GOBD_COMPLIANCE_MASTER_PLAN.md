# GoBD readiness and audit plan for unified Filament Accounting

**Version:** 0.1 working plan

**Architecture effective:** 3 September 2026

**Product repository:** `fliix-cloud/filament-accounting`
**Protocol dependency:** `nemiah/php-fints`

This plan defines technical readiness and evidence goals. It is not legal advice,
an audit opinion, or a statement that an installation is “GoBD certified”. The
deploying organization remains responsible for its procedures, permissions,
infrastructure, retention, backups, and tax assessment.

## Architecture boundary

[ADR 0003](adr/0003-unified-accounting-package.md) defines the product boundary:

- the host installs one product package and registers one Filament plugin;
- accounting, FinTS application integration, reconciliation, tax, documents,
  ownership, and audit are modules in the same Laravel package;
- `nemiah/php-fints` is a transitive, framework-free protocol dependency;
- every persisted banking and accounting record belongs to the same trusted
  Legal Entity boundary.

Protocol changes are not maintained locally. Candidate upstream changes must
pass the evidence and verification process documented in
[php-fints-upstream-policy.md](upstream/php-fints-upstream-policy.md).

## Product scope

The initially assured profile is a company established in Germany with:

- customer and supplier workflows over a shared party identity;
- sales and purchase invoices, original attachments, PDF and supported
  structured e-invoice artifacts;
- versioned German tax rates and explained DE/EU/non-EU suggestions;
- double-entry journals, open items, settlements, periods, and reversals;
- FinTS connections, canonical accounts, balances, transactions, SCA, transfers,
  direct debits, and mandates;
- direct/partial reconciliation and exact multi-target splits;
- deterministic local suggestions and user-confirmed learning rules;
- audit-chain, external anchor, integrity, and evidence-export mechanisms.

Not included are fixed assets, cash-register/TSE/DSFinV-K, payroll, group
consolidation, arbitrary user-programmable tax/posting rules, or comprehensive
tax compliance for companies established outside Germany. DATEV-style output is
not a substitute for complete Z1/Z2/Z3 access.

## System and trust boundaries

```text
Users / host identity and authorization
                │
                ▼
fliix-cloud/filament-accounting
├── Ownership + Actor + Tenant context
├── Documents + Tax + E-Invoicing
├── Banking/FinTs + Reconciliation
├── Ledger + Periods
└── Audit + Evidence + Export
                │
                ├── database
                ├── original/e-invoice storage
                ├── independently controlled audit-anchor storage
                └── nemiah/php-fints → bank FinTS endpoint
```

`LegalEntity` is the product integrity and reporting boundary. It is resolved
from trusted host context, never from untrusted request input. Actors are resolved
separately. Queue jobs carry scalar identities and activate tenancy before model
queries. Cross-tenant access is tested at service and Resource boundaries.

The host owns identity, role assignment, deployment, database administration,
key management, time synchronization, scheduler/queue operation, object lock,
backup/restore, monitoring, retention execution, and procedural documentation.

## Accounting integrity

Posted journals are balanced and immutable. Corrections create linked reversal
entries. Documents and lines retain commercial, counterparty, tax, account, and
amount decisions required by their lifecycle. Open-item status is derived from
active settlements rather than a mutable payment flag. Finalization locks the
bank transaction and target state and is idempotent.

All business amounts use integer minor units and exact decimal conversion. There
are no float-based posting or data-conversion calculations. Tax and posting decisions
are versioned; a used tax-rate version cannot be changed in place.

Period close/reopen, manual posting, reversal, and other privileged operations
must be ability-protected and audit logged. Operational separation of duties and
four-eyes approval remain host controls until a dedicated workflow exists.

## Documents and tax

Original uploads use configured Laravel storage and SHA-256 integrity metadata.
Issued/received document snapshots and supported e-invoice artifacts are kept
with the document. Storage capabilities, put-once behavior, object versioning,
retention lock, deletion control, backup, and restore must be attested by the host.

Germany-first tax seeding provides standard, reduced, zero, historical 16%/5%,
EU intra-community/reverse-charge, and export treatments. Suggestions consider
seller country, recipient country, business/private status, VAT ID, goods/service,
date, and item tax class. Ambiguous cases remain unconfirmed and require a user.
The software does not claim complete foreign tax advice.

Purchase invoices begin with an original upload or explicit manual intake. XML
or supported e-invoice PDF can prefill data; normal PDFs remain manual. Every
purchase requires a business category confirmed by the user. Internal ledger
mapping is deterministic and hidden from the normal UI.

## Bank-source evidence and reconciliation

FinTS writes directly to the canonical account/transaction models. Each material
source state (including pending→booked, bank correction, and storno) creates an
append-only source version containing stable source identity, normalized payload,
available raw payload, status, hash, import run, and capture time. Identical retry
evidence does not create duplicates.

A source change after posting does not silently change booked accounting values;
it creates evidence and a review flag. Reconciliations use reversal rather than
in-place mutation of final results. Split requires at least two positive, currency-
matching allocations whose signed sum exactly equals the transaction.

Suggestions expose score and reasons. Learning rules are created only from a
confirmed finalization, are scoped to Legal Entity and transaction direction,
can be edited/deactivated/deleted, and never cause automatic posting in 0.1.

## Audit chain and anchors

Critical operations create per-Legal-Entity sequential audit events with actor,
timestamp, target, operation, canonical payload, previous hash, and event hash.
The model blocks ordinary update/delete paths. `filament-accounting:verify`
checks posted-journal balance and audit/anchor integrity and can emit versioned
JSON. Evidence can be exported and verified offline.

An application database administrator can bypass ORM guards. Therefore external
anchors must be written to independently controlled immutable/versioned storage,
with separate permissions and retained schedule/monitoring evidence. The host
must prove that configuration rather than relying on the package to infer it.

## Fresh schema evidence

Version 0.1 has no external predecessor installation. The package migrations
therefore describe only the final target schema and are verified from an empty
database. Development environments are recreated with `migrate:fresh`.

## Data access, retention, and operations

- Z1: authorized read-only access to current and retained records.
- Z2: reproducible reports and queries under supervised operation.
- Z3: a deterministic complete relational export with manifests and checksums is
  still a documented gap; generic journal CSV alone is not sufficient.
- Retention classes, legal hold, controlled disposal, and disposal evidence need
  an executable host/package contract before being called complete.
- Database, originals, anchors, keys, configuration, code/release manifest, and
  procedure documents must be backed up together and restored in exercises.
- Integrity failures, missing anchors/evidence, failed bank syncs, ambiguous
  payment submissions, expired SCA, queue failures, and backup failures require
  monitored operational handling.

## Reference release gate

Every release candidate must retain:

1. exact PHP/Laravel/Filament/package/commit and migration manifest;
2. `composer validate --strict --no-check-publish` output;
3. full PHPUnit results without live bank credentials or paid services;
4. PHPStan and formatting results;
5. fresh-install schema and seed results;
6. bank source-version, idempotency, SCA, payment, mandate, tenant, tax,
   reconciliation, document, ledger, and audit evidence tests;
7. configuration and host responsibility attestations;
8. reviewed changes to this plan and the control matrix.

The narrow proposed audit stack remains PHP 8.4, Laravel 13, Filament 5,
PostgreSQL 18, supervised queue/scheduler operation, private S3-compatible
evidence storage with versioning/object lock, and the Germany profile. Exact
patches and vendors belong in the frozen release manifest.

## Open readiness work

The following items remain explicit rather than being overstated:

- independent legal/audit assessment and reference-host evidence;
- database-level roles/constraints against direct mutation;
- end-to-end immutable original-storage attestation and restore evidence;
- complete deterministic Z3 package and round-trip verification;
- executable retention/legal-hold/disposal workflow;
- operational four-eyes and access-recertification evidence;
- signed/reproducible releases, SBOM, and protected-branch evidence.

The detailed status and owners are maintained in
[GOBD_CONTROL_MATRIX.md](GOBD_CONTROL_MATRIX.md).
