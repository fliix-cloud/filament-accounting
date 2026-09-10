# GoBD readiness

Reviewed: 5 September 2026, [commit 99b2218](https://github.com/fliix-cloud/filament-fints-accounting/tree/99b221895511d8c42c1228b36bd3e9f75c0d3e39).

## Verdict

The current package is **not ready for an unqualified “GoBD-konform” claim**.
Useful controls exist, but evidence preservation, accounting correctness,
authorization, and audit access still have release-blocking gaps.

A defensible, scoped claim is achievable. It requires a tested release **and**
an evidenced operating procedure. Installing an open-source package cannot
guarantee the compliance of every host application or deployment.

This is a repository-wide, risk-based technical review of the ledger, documents,
tax, banking, reconciliation, authorization, storage, audit, exports, migrations,
UI integration, tests, and CI. It is not an exhaustive security audit, legal
opinion, or certification. Production infrastructure and business procedures
were not inspected. The baseline findings describe source-level observations
from 5 September: PHP and Composer were unavailable in that review environment.
Subsequent implementation checks were executed locally; their results and the
latest project-state check are recorded below.

## Legal basis and claim boundary

The reviewed basis is [AO § 146][ao146], [AO § 147][ao147], the
[GoBD text through March 2024][gobd], and the [July 2025 amendment][amendment].
The linked AO handbook does not incorporate that amendment; read them together.

- GoBD Rz. 21 and 23: responsibility and assessment include the taxpayer's
  actual system and procedures, not software alone.
- Rz. 100–111: controls must address access, loss, changes, and historical meaning.
- Rz. 150–154: procedures and their changes need understandable documentation.
- Rz. 179–181: the tax authority does not issue a generally binding software
  approval; third-party test reports do not bind it either.

**Current public wording:** “Provides double-entry bookkeeping, audit-chain
verification, and document-integrity controls. GoBD readiness is under review.”

**Target wording, only after the release gates below pass:**
“Version X provides the technical requirements for GoBD-compliant processing
within the documented scope when deployed and operated according to the
specified requirements.” Publish the scope and evidence alongside the claim;
obtain accounting and legal review of any stronger German marketing wording.

Do not equate GoBD readiness with complete VAT correctness, XRechnung
conformance, DATEV compatibility, or statutory financial statements.

## Implementation progress

Updated: 10 September 2026. The table below tracks changes after the reviewed
baseline; the detailed findings retain that baseline as their reference.
**No finding is fully closed and the compliance verdict is unchanged.**

Project-state check after the reported interruption, 10 September 2026: the working
tree was clean at `d4ce364`; the durable intake, outgoing artifact recovery, and
scheduled verification changes are committed. The current verification code and
operations documentation agree on report schema 2 and separate integrity/pending
results. Default audit export remains schema 1 with events and anchors only. The full local
quality gate was rerun on Herd PHP 8.4.25: **241 tests, 2,265 assertions**, PHPStan,
Pint, and strict Composer validation passed. Local links in this document and
operations documentation resolve. Graph access is restored, but coverage metadata
reported changed file metadata, so material checks used current source as well.
This was a reconciliation of the latest implementation and documentation, not a
new legal review or a production database/storage recovery test. Only documentation
clarifications were needed; the next implementation step remains the linked export.

| Findings | Implemented in this change | Still required |
| --- | --- | --- |
| F11 / F9 | New `StoreAttachment` writes retain files after failure and verify retries. Outgoing invoices now commit a fixed PDF/XML set, render snapshot, paths, hashes, and preparation evidence before file writes. Retries use staged bytes, recover missing attachment references, and verify contents before posting. Issuance uses the accounting connection. Filament offers “Complete invoice” for interrupted issuance. | General orphan recovery, production concurrency/storage/restore evidence, independent offline verification/export of the complete artifact dataset, and a deployment migration. |
| F1 / F3 / F7 / F9 / F11 | A committed intake manifest and verified private raw files precede parsing. PDF, standalone XML, and PDF/XML pairs are supported. Identity includes roles and contents. Attempts are audited; retry reuses preserved inputs. Business rollback retains intake evidence. Purchase registration uses the accounting connection. Source-total mismatches block conversion. Filament exposes open imports, safe downloads, and retry within purchase invoices. | Production concurrency and crash tests, complete conformance/accounting conversion checks, derived-file orphan recovery, complete converted-line evidence, and independent offline export/verification. |
| F1 / F3 | Purchase draft disposal retains the document, lines, PDF/XML, and actor/reason evidence. It requires a dedicated permission, current company scope, and a locked persisted draft. UI offers “Discard draft”; physical deletion is disabled. Invalid accepted imports are now retained independently of drafts. | Complete operational review/correction of blocked intakes and production retention evidence. |
| F2 / F4 | Original attachment metadata and original-file model deletion are guarded. Documents reject final-state downgrades and identity changes; lines reject reparenting and consult stored parent state. Stale journal models cannot edit posted data. | Bulk/SQL write prevention, concurrent mutation evidence, and controlled correction workflows. |
| F2 / F8 / F10 | Each ledger posting includes a versioned full journal snapshot and SHA-256 digest in its audit event. Verification compares both directions and detects changed/missing journal data. Account/period values are frozen at posting. CSV exports use checked historical records and refuse integrity failures; the journal UI uses historical account codes. | Bind document, attachment, settlement, and other business contents to evidence; protect storage and database privileges; complete the machine-readable audit export. This is journal tamper detection, not prevention of privileged SQL writes. |
| F3 | Undefined Gates now deny access; the provider no longer creates permissive fallback Gates. Tests explicitly configure fixture permissions; hosts must configure their own Gates. | Complete the authorization audit of all public mutation paths and integrations. |
| F5 | Closing cannot weaken a hard lock. Reopening requires a separate permission and non-blank reason. Both record before/after state, use the accounting connection, and lock entity before period. Repeated close is idempotent. | Production database concurrency tests and protection against direct period-model/SQL changes. |
| F6 / F9 | Posting reloads persisted state and accepts issued/received invoices and credit notes. Foreign currency is rejected until conversion exists. Line discounts apply before tax. Non-recoverable purchase tax stays on the expense account. Sequence uses the posted-on year. Ledger posting/reversal and changed document/period services use the accounting connection. | Remaining tax edge cases; connection consistency in the remaining services and cross-connection rollback tests. |

Regression coverage: [document protection](../tests/Documents/RecordProtectionTest.php),
[ledger/period protection](../tests/Ledger/RecordProtectionTest.php),
[authorization](../tests/Authorization/DefaultAccountingAuthorizerTest.php),
the [Filament discard workflow](../tests/Filament/InvoiceLayoutTest.php), and
[journal evidence/export tests](../tests/Audit/JournalIntegrityTest.php).
[CI for implementation commit d8aa32c](https://github.com/fliix-cloud/filament-fints-accounting/actions/runs/33958922570)
passed all 184 tests on PHP 8.3/8.4/8.5, PHPStan, Pint, and Composer validation
(1,843 assertions on PHP 8.3). This includes balanced SQL changes, missing/duplicate
evidence, snapshot validation, rollback, stable historical CSV/UI values, and a
coordinated local-hash rewrite detected by an external anchor.
The attachment-storage continuation was tested locally with Herd PHP 8.4;
regressions cover separate objects for identical content, failed metadata saves,
failed storage verification, and missing/corrupt retry evidence. The full suite
passed 204 tests with 1,916 assertions; all seven attachment tests also passed
after shortening the storage paths. PHPStan reported no errors. These tests do not establish
production concurrency or storage immutability. F7–F12 are not fully resolved;
see [operations](operations.md).

The caller-failure continuation passed the full local suite on Herd PHP 8.4.25:
211 tests, 1,995 assertions. New [import regressions](../tests/Documents/PurchaseInvoiceUploadTest.php)
and [artifact regressions](../tests/Documents/InvoiceArtifactTest.php) cover
injected metadata failures after object writes, retained bytes/rows and original
exceptions, incomplete and repeated retries, missing/corrupt PDF and XML,
changed companion XML, denied import access, and renderer upgrades. After the
final type-guard correction, all 14 affected tests passed again (128 assertions);
PHPStan and the full Pint check passed as well.

**Development schema:** there are no installed/production databases to migrate.
The base migration now includes journal `period_snapshot` and line
`account_snapshot` JSON columns. Drafts may omit them; posted entries without
complete snapshots and exactly one posting event fail verification. Rebuild
disposable DEV databases; no backfill or legacy-evidence acceptance is supplied.

## Current continuation: durable intake with a simple Filament workflow

Targeted continuation: 9 September 2026. This is not a new repository-wide
review. Graph discovery and coverage checks failed with `Transport closed`;
the affected source files were read directly.

### Implemented: preserve inputs before processing (F1 / F3 / F7 / F9 / F11)

[PurchaseInvoiceIntakeStore](../src/Services/PurchaseInvoiceIntakeStore.php)
authorizes the company and actor, checks permitted extensions and size, and
commits a [company-scoped intake](../src/Models/PurchaseInvoiceIntake.php) before
writing files. Its manifest records original filenames, roles, expected paths,
sizes, and SHA-256 hashes. Each verified file is marked preserved in a separate
commit. Input bytes are stored privately as inert `.bin` objects, including
malformed XML and XML with prohibited document type declarations. XML entities
are never resolved by the import entry point. Unsupported file types, oversized
uploads, and unauthorized requests are rejected before acceptance.

[ImportPurchaseInvoice](../src/Services/ImportPurchaseInvoice.php) accepts PDF,
standalone XML, and PDF with companion XML. Identity includes all supplied
content hashes and roles, scoped by company. An entity lock and a database unique
constraint serialize creation of the intake; processing locks entity then intake.
These mechanisms still require concurrent-request proof on the chosen production
database. Sequential retries have been tested; they do not establish that proof.

All raw inputs must be preserved and verified before parsing and business writes.
Supplier, draft, attachment references, completion link, and completion audit event
use the accounting connection's transaction. If this transaction fails, its new
business records roll back while the committed intake and originals survive.
This supersedes the earlier behavior of keeping a partially created import draft.
PDF/XML document attachments reference the retained intake objects. Extracted XML
from a hybrid PDF still uses `StoreAttachment`; the retained PDF contains the
original structured bytes, but a failed derived-file metadata save can still
leave a separate object without an attachment row.

Retries verify the manifest and existing bytes. A file found at its planned path
after an interrupted metadata commit is verified and reused. A missing file that
was already marked preserved, or any changed bytes, blocks processing; supplied
upload bytes never silently repair that evidence. An interrupted initial write
can be completed by uploading the same inputs again. `resume($intake)` processes
preserved inputs without another upload and reuses an already linked, verified
document. Attempts have correlated start/completion/failure audit events. If the
failure event cannot be committed, the start event and prior intake state remain;
the recording error is reported without masking the processing exception.

Intake rejects an enclosing accounting transaction because a later host rollback
would erase the claimed preservation record. Filament's upload page and retry
action disable their outer transaction. Integrations must invoke intake outside
a host accounting transaction. Connection isolation and rollback were tested with
two SQLite connections; this does not prove production locking behavior.

Parsing success is recorded separately from conformance (`extracted` versus
`validation_status: not_checked`). Source net, tax, and gross totals must equal
the calculated draft totals; mismatches roll back business conversion and leave
the original available for review. Full schema/business-rule validation and all
allowance, charge, rounding, and tax cases remain open.

### Filament behavior

The normal flow remains **upload → review → book**. The existing main upload
field accepts PDF or XML; companion XML remains optional. No parser, archive,
hash, or recovery-policy settings are exposed.

**Open imports** is a page within purchase invoices, with filename, receipt time,
and a short status. It includes intakes that have no draft. Verified raw originals
can be downloaded as attachments with an inert content type; they are not rendered
inline. Error details are hidden by default. Preserved, interrupted processing
can be retried and leads directly to the existing invoice review. Invalid input
and integrity failures show a review state; integrity failures have no retry
button. Incomplete preservation instructs the user to upload the same files again.
Authorization and company isolation are checked in services as well as the page.

No additional accounting form fields, compliance dashboard, or approval wizard
were added. Invalid inputs are retained rather than presented as editable invoices.
A corrected source is a distinct intake; replacing originals or silently clearing
blocked intakes is not provided.

### Evidence and remaining boundaries

[PurchaseInvoiceUploadTest](../tests/Documents/PurchaseInvoiceUploadTest.php)
now uses real commits rather than a surrounding test transaction. It covers
standalone XML, malformed/unsafe XML preservation, source-total mismatch, changed
companion XML, storage failure, repeated metadata failure, interrupted-write
recovery, missing/corrupt originals, forbidden outer transactions, a separate
accounting connection, manifest guards, denied access, and the Filament upload,
review, download, and retry flows. The full local suite passed on Herd PHP 8.4.25:
**220 tests, 2,097 assertions**.
After adding coverage for standalone CII, conflicting hybrid/companion XML, and
preservation-marker protection, the 19 import/UI tests passed again with 178
assertions. PHPStan and the full Pint check passed.

The base DEV migration adds `accounting_purchase_invoice_intakes`. Rebuild only
disposable DEV databases. Old imports are not automatically attached to fabricated
intake history. No production migration or backfill is supplied.

General `StoreAttachment` writes still need broader orphan recovery. The outgoing
invoice continuation below now provides a fixed artifact set and recovery of
partial file writes and missing attachment references.

Intake manifest and preservation events join the existing audit chain. The
scheduled verification slice below now checks manifests, originals, and completion
links alongside chain and configured anchor verification. Complete converted-line
evidence and independent offline export verification remain open. Model guards and
row locks do not prevent raw SQL or privileged storage changes. Production
concurrency, create-only/immutable storage controls, restoration, monitoring, and
operator response still need evidence.

### Implemented: recoverable outgoing invoice sets (F2 / F3 / F9 / F11)

[GenerateInvoiceArtifacts](../src/Services/GenerateInvoiceArtifacts.php) now
commits an [InvoiceArtifactSet](../src/Models/InvoiceArtifactSet.php) before its
first filesystem write. The set holds the exact XML and base64-encoded PDF,
the rendered document snapshot, renderer/template metadata, planned object paths,
filenames, sizes, and hashes. Its evidence digest is recorded in a preparation
audit event. The staged bytes are retained as evidence, not deleted after upload.

Each file write, read-back verification, attachment reference, and preservation
marker is processed under accounting-connection locks. A retry uses the committed
bytes even after renderer or configuration changes. Objects found at their planned
paths after an interrupted metadata commit are verified and reused; missing
attachment rows can be reconstructed from the set. Missing files already marked
preserved, changed bytes, duplicate references, changed render-source values, and
altered local preparation evidence block continuation. Generated attachments and
the set reject model deletion; preservation markers cannot be reset through models.
If the set disappears but its preparation event remains, regeneration is refused
even when every attachment row is also absent.

Verification compares staged bytes, manifest, current render-source snapshot,
attachment metadata/files, and the preparation event's payload, canonical payload,
and hash. These are local checks. The scheduled verification command now checks
these sets alongside the full audit chain and configured independent anchors.
The default audit export contains events and anchors; the dataset option described
below additionally transfers linked accounting records and retained files.

[IssueSalesInvoice](../src/Services/IssueSalesInvoice.php) freezes the artifact
requirement when issuing. Changing the current generation setting cannot skip
an already-required artifact step. Issuance and its sequence/audit updates use the
accounting connection; generation requires an independent commit and rejects an
enclosing accounting transaction. [PostDocument](../src/Services/PostDocument.php)
verifies required or existing sets before posting. A retry keeps the invoice number
and uses existing posting idempotency to avoid a second journal.

Filament exposes **Complete invoice** only for issued invoices with pending
completion and the required permissions. The action is available in the sales
list and invoice view and runs without an outer transaction. It reports failure
without deleting evidence; successful completion leads to the existing invoice
view. No additional accounting fields or artifact-version choices are required.

[InvoiceArtifactTest](../tests/Documents/InvoiceArtifactTest.php) covers repeated
metadata failure, PDF storage failure, renderer upgrades, lost attachment rows,
lost authoritative sets, stale models after source tampering, staged-byte and
preparation-event tampering, model deletion guards, denied access, outer-transaction
rejection, separate accounting-connection recovery, and Filament completion with
exactly one invoice number and journal. Local tests use real commits and fake
storage; production concurrency and crash/restore behavior remain release gates.
The subsequent verification slice and its regression results are recorded below.

The base DEV migration adds `accounting_invoice_artifact_sets`. Include this table
and its staged contents in retention, backup, restore, and future audit exports.
Existing generated attachments without a set are not silently adopted. Rebuild
only disposable DEV databases; no production migration or evidence backfill is
provided. First rendering can be retried if it failed before committing a set,
because no artifact file has yet been written by this workflow.

### Scheduled invoice evidence verification

`filament-accounting:verify` now runs
[InvoiceEvidenceVerifier](../src/Audit/InvoiceEvidenceVerifier.php) under the same
entity lock as journal, chain, and anchor verification. It checks intake manifests
against creation events, preservation markers against file events, stored sizes
and hashes, completion links, and original attachment references. It also checks
outgoing staged bytes, render snapshots, generated attachments, and preservation
evidence, including interrupted sets. Reverse checks from audit events and invoice
links detect missing or reassigned evidence records. Existing files at planned but
not yet committed paths are checked as well; verification never repairs them.

The command's JSON report is now **schema version 2**. Each entity has an
`invoice_evidence` section with counts, `issues`, and `pending` lists. Integrity
issues cause a non-zero exit code. An uncompleted intake (including preserved but
unsupported XML) or interrupted unposted issuance is reported separately as
pending, without asserting corruption. A posted invoice with required but missing
or incomplete artifacts is an integrity failure. Pending work also appears as
warnings in text output; operators must monitor it separately from exit codes.
No Filament fields or additional user steps were added.

[InvoiceEvidenceTest](../tests/Audit/InvoiceEvidenceTest.php) and the invoice tests
cover deleted/changed files, rewritten manifests, reset preservation state,
deleted/reassigned intakes, changed render inputs/staged PDF data, missing sets,
completed import links, rejected XML, and interrupted issuance. Verification works
without a web actor and on the separate accounting connection. The graph tools
again returned `Transport closed`; the affected implementation was checked through
direct source inspection. These tests do not prove production locking, storage
immutability, or resistance to coordinated rewriting without independent anchors.

The full local suite passed on Herd PHP 8.4.25 with **241 tests and 2,265
assertions**. PHPStan, Pint, and strict Composer validation passed.

This extends invoice-original and outgoing-render evidence checks, not a complete
snapshot of every business relation. Intake verification does not yet bind every
converted invoice line to the incoming structured data. The linked export below
adds offline package verification within its explicit scope. General attachment-
orphan inventory and independent import/restore evidence remain open. The command reads retained files; schedule and measure it against
the deployment's actual dataset and storage performance.

### Linked accounting dataset export (F10)

The `--dataset` option on `filament-accounting:audit-export` now transfers a
company-scoped package with 36 explicitly selected tables, retained file contents,
column and relationship descriptions, audit events, and anchors. See
[AccountingDatasetSchema](../src/Export/AccountingDatasetSchema.php),
[AccountingDatasetExporter](../src/Export/AccountingDatasetExporter.php), and
[operations](operations.md) for the exact scope and command examples. Child tables
are scoped through their parent. Originals shared by intake and attachment
references occur once per storage path; unpreserved missing inputs are explicitly
marked absent. Preserved missing or changed files block export. Outgoing staged
PDF/XML data and pending processing records are retained in the transfer.

The existing integrity checks run before a dataset digest is recorded in the
`accounting_export.prepared` audit event. File delivery happens after that event's
commit; `prepared` is not proof of delivery. Optional `--anchor` anchors committed
evidence before delivery. Without it, an included earlier anchor need not cover
the new export event, which the report exposes as `export_event_anchored: false`.
Independently trusted hashes/anchors are still needed to detect replacement of
the complete package and its local evidence.

[AccountingDatasetVerifier](../src/Export/AccountingDatasetVerifier.php) checks the
package without database or source-storage access. It checks the fixed schema,
company ownership, record inventory/IDs, declared references, file inventory and
bytes, dataset commitment, audit chain, and included anchors. Changing data and
recalculating only the package hash does not satisfy the recorded commitment.
The default audit-only schema and its tests remain supported. This initial JSON
package is identified by `format: filament-accounting-dataset`, schema version 1;
it remains available with `--dataset --legacy-json`. Streaming version 2 below
is now the default for `--dataset`.

[AccountingDatasetTest](../tests/Audit/AccountingDatasetTest.php) exercises the
invoice → journal/open item → settlement/reconciliation → bank path, outgoing and
incoming originals, pending intakes, tenant separation, omitted connection secrets,
offline verification without queries, altered/removed content, fully rehashed local
forgery against an anchor, separate accounting connections, invalid destinations,
and overwrite refusal. Filament receives no additional fields or mandatory steps.
The full local suite passed on Herd PHP 8.4.25 with **249 tests and 2,325
assertions**; PHPStan, Pint, and strict Composer validation passed.
Graph access failed again during this slice; changed/unavailable coverage was
supplemented with direct source and migration inspection.

This transfers the current supported dataset and binds it at export time. It does
not retroactively provide missing finalized settlement or purchase-line history.
Host records, TAN sessions, connection credentials/state, institute-directory data,
unreferenced storage objects, and logo/template assets are outside this package.
Unsupported attachment-owner types fail explicitly. There is no web export action
yet: the builder is a trusted operator service, and a future UI must add explicit
authorization and company scope. Production snapshot consistency under concurrent
writes, independent third-party import, and full restore evidence remain open.
F10 and the release gates are not closed.

### Streaming transfer and isolated inspection (F10)

[StreamDatasetExporter](../src/Export/StreamDatasetExporter.php) now makes
`--dataset` a version 2 streaming transfer with lazy record/event reads and 64 KiB
file chunks. Journal preflight checks entries incrementally; pending invoice work
is emitted without accumulating the complete pending list. A dataset digest binds
the exact body bytes to the committed export event. Delivery is verified by
reading back the package and comparing its full transport digest. No Filament
fields or additional mandatory user steps were introduced.

[StreamDatasetVerifier](../src/Export/StreamDatasetVerifier.php) checks the framed
stream using a disposable SQLite index rather than loading the complete package.
It validates schema, ownership, references, file bytes, audit chain, anchors, and
footer counts, and rejects truncation or trailing input. A requested covering
anchor cannot simply be removed. Legacy JSON and audit-only packages remain
supported. See [operations](operations.md) for format, commands, temporary storage,
and the required `pdo_sqlite` extension.

[StreamDatasetTest](../tests/Audit/StreamDatasetTest.php) exercises an isolated
inspection database after removal of source originals and audit events. It
reconstructs original file bytes, independently queries equal debit/credit totals,
and joins invoice, open item, settlement, reconciliation, and bank transaction
without host database queries. Further cases cover interrupted issuance and
blocked intakes, corrupt journals, damaged packages, anchor removal, and command
delivery/readback. The load fixture exports more than 48 MiB of originals into a
package exceeding 64 MiB with less than 24 MiB additional PHP memory.

This is an inspection import using the package's own verifier, not independent
third-party interoperability or a full production restore. Frames are bounded at
64 MiB, but the largest single row/invoice and anchor list still affect memory.
Temporary disk requirements and production-scale performance need deployment
measurements. Preparation and evidence capture use separate entity-locked
transactions; concurrent production snapshot behavior remains unproven.

The full local suite passed on Herd PHP 8.4.25 with **255 tests and 2,363
assertions**; PHPStan, Pint, strict Composer validation, and documentation link
checks passed. Graph transport was unavailable during this slice,
so relevant implementation and test evidence was checked directly in source.

### Next slices

1. Prove consistent dataset snapshots under concurrent writes on the selected
   production database, measure deployment-scale memory/temp storage/lock duration,
   and exercise independent import and full restore. Add an authorized, simple
   export action in Filament once those boundaries are established.
2. Establish operator monitoring for integrity errors and pending work, and prove concurrent
   duplicate requests, process termination, and recovery on the selected
   production database/storage setup (F2 / F7 / F9 / F11).
3. Complete public-service authorization and connection consistency, finalized
   business evidence, and controlled corrections (F2–F4 / F8–F9).
4. Complete supported tax cases and bank catch-up completeness, then read-only
   inspection, linked export, and the release/operating evidence (F6 / F10 / F12).

The other open P0 findings and all release gates still apply. This continuation
does not authorize a GoBD-readiness claim.

## Existing foundation

| Area | Evidence in the reviewed code | Assessment |
| --- | --- | --- |
| Ledger | [FirstPartyLedgerEngine](../src/Ledger/FirstPartyLedgerEngine.php), [ledger tests](../tests/Ledger/LedgerEngineTest.php): balance checks, numbering, idempotency, period locks, linked reversals | Useful foundation; see F2–F6 |
| Documents and tax | Party/company snapshots, confirmed expense categories, [TaxRuleVersion](../src/Models/TaxRuleVersion.php) reference/overlap checks, [invoice tests](../tests/Documents/InvoiceFlowTest.php) | Partial lifecycle protection |
| Banking and reconciliation | Source versions, booked-only finalization, exact splits and settlements; [import tests](../tests/Banking/UnifiedBankTransactionImporterTest.php), [reconciliation tests](../tests/Reconciliation/ReconciliationTest.php), [payment safety tests](../tests/Banking/FinTs/PaymentSubmissionSafetyTest.php) | Good mechanisms; not proof of complete bank records |
| Audit and files | Canonical event chain, external anchors, offline evidence verification, SHA-256 checks on attachment reads; [audit tests](../tests/Audit/AuditAnchorTest.php), [attachment tests](../tests/Attachments/AttachmentStorageTest.php) | Detects specific failures, not all business-data changes |
| Quality checks | [Baseline CI](https://github.com/fliix-cloud/filament-fints-accounting/actions/runs/33943471032): PHPUnit on PHP 8.3/8.4/8.5, PHPStan, Pint, Composer validation passed | Existing suite is green; compliance gaps remain |

## Baseline findings and acceptance criteria

The findings describe commit 99b2218. Code links locate the affected files;
the progress table above records subsequent corrections and remaining work.

P0 means a direct integrity/access risk. P1 means another mandatory item before
the scoped readiness claim. These priorities are engineering judgments, not
official GoBD classifications.

| ID | Baseline finding and code location | Required outcome / regression evidence |
| --- | --- | --- |
| F1 · P0 | [DeletePurchaseInvoiceDraft](../src/Services/DeletePurchaseInvoiceDraft.php) deletes the received PDF/XML and metadata without a retained deletion event. [InvoiceLayoutTest](../tests/Filament/InvoiceLayoutTest.php) explicitly expects this deletion. A draft booking does not make a received original disposable. | Preserve tax-relevant originals from intake, including rejected/invalid imports. Discard the booking draft separately, with actor/reason and a retained intake record. Never remove the only retained copy of a received invoice. |
| F2 · P0 | `journal.posted` logs sequence/source type, not journal amounts/accounts. [VerifyCommand](../src/Commands/VerifyCommand.php) checks balance and line count, but does not bind journal/document/settlement contents to audit hashes. A balanced SQL change or account substitution can leave these checks green. [Attachment](../src/Models/Attachment.php) has no update/delete guard. | Protect finalized records and references against application and bulk-write paths; verify canonical business snapshots against independently anchored evidence. Test balanced tampering, missing records, changed attachments, and privileged mutation. ORM events and self-contained hashes alone are insufficient. |
| F3 · P0 | [DefaultAccountingAuthorizer](../src/Authorization/DefaultAccountingAuthorizer.php) allows any resolved actor when a Gate is undefined. `DeletePurchaseInvoiceDraft` has no service-level authorization. | Deny undefined abilities, validate every public mutation service, and test anonymous/read-only users through services and UI. Provide an explicit least-privilege setup; do not rely on hidden navigation. |
| F4 · P0 | [Document](../src/Models/Document.php) does not protect status/ownership fields as commercial fields: a saved transition back to draft can remove later commercial-field protection. [JournalLine](../src/Models/JournalLine.php) and [DocumentLine](../src/Models/DocumentLine.php) inspect the current parent, not both original and new parents. | Enforce allowed state transitions and immutable parent/owner links. Test status downgrade across two saves, line reassignment, stale loaded relations, and bulk updates. Add controlled correction workflows rather than editable posted states. |
| F5 · P1 | [CloseAccountingPeriod](../src/Services/CloseAccountingPeriod.php) can replace hard-closed with soft-closed via `hard: false`; the ledger blocks only hard-closed. [ReopenAccountingPeriod](../src/Services/ReopenAccountingPeriod.php) accepts an empty reason. | Prevent close from weakening a lock. Require separately authorized, non-empty-reason reopening with before/after history. Test backdated and concurrent posting against closure. |
| F6 · P1 | [PostDocument](../src/Services/PostDocument.php) uses [JournalLineDraft](../src/Ledger/JournalLineDraft.php) helpers that copy transaction amounts into base amounts without applying the exchange rate. It does not require issued/received status. Sales credit notes follow the sales-invoice debit/credit branch. Invoice line writers store `discount` but calculate quantity × price without it. | Reject unsupported currencies/features server-side or implement them correctly. Require a valid document lifecycle before posting. Test FX, discounts, credit notes, non-recoverable tax, mixed/zero rates, and rounding against reviewed expected journals. A balanced journal is not necessarily correct. |
| F7 · P1 | [ImportPurchaseInvoice](../src/Services/ImportPurchaseInvoice.php) requires a PDF, parses before preservation, and deduplicates by PDF hash before considering separately supplied XML. [UblEInvoiceParser](../src/Documents/UblEInvoiceParser.php) checks basic fields, not complete format/business rules. Source totals are metadata; registration recalculates lines without reconciling those totals. | Accept and retain standalone XML; archive first, validate second. Use the structured content in import identity. Report parse success separately from conformance. Test identical PDF/different XML, allowances/charges, invalid XML, and source-total mismatches; block unsupported accounting conversion without losing the original. |
| F8 · P1 | [PostingRuleVersion](../src/Models/PostingRuleVersion.php), [LedgerAccount](../src/Models/LedgerAccount.php), settlements, and reconciliation splits lack comparable finalized-history guards. The CSV exporter reads current account names/codes. Draft updates replace lines without recording before/after values. | Preserve the historical meaning of used mappings and tax-relevant intake changes. Test later master-data edits against old exports and document history. Distinguish disposable, unissued sales working drafts from records already introduced into accounting processing. |
| F9 · P1 | Package models support `ACCOUNTING_DB_CONNECTION`, but ledger/document/reconciliation/payment services use default `DB::transaction` while [AuditLogger](../src/Services/AuditLogger.php) uses the entity connection. With different connections, rollback/locking boundaries need not cover the business writes. | Use one explicit accounting connection for related writes, locks, audit, and after-commit events, or reject unsupported configurations. Inject failures midway and prove complete rollback on the supported production database, including concurrent requests. |
| F10 · P1 | [GenericJournalCsvExporter](../src/Export/GenericJournalCsvExporter.php) exports journal rows, not the complete retained accounting dataset with machine-readable relationships. Audit JSON exports events/anchors, not all records. Journal views do not establish complete account-ledger reporting or Z1/Z2/Z3 access. | Implement authorized read-only inspection, requested evaluations, and scoped machine-readable transfer of records, originals, structures, and relationships. Prove document → journal → settlement → bank and reverse tracing, stable historical exports, totals, and independent import. Do not export credentials or unrelated personal data. |
| F11 · P1 | [StoreAttachment](../src/Services/StoreAttachment.php) uses ordinary `put` and error cleanup deletes. Paths depend on company/hash/filename, not the owning document. [GenerateInvoiceArtifacts](../src/Services/GenerateInvoiceArtifacts.php) may regenerate after renderer changes; issuance is committed before artifacts/posting finish. | Define immutable originals and the authoritative issued artifact. Prevent cleanup from deleting pre-existing/shared objects. Test interrupted issuance, retry, storage failure, restore, and renderer upgrades; expose incomplete operations for recovery. |
| F12 · P1 | [TransactionSyncService](../src/Banking/FinTs/Services/TransactionSyncService.php) clips requested history to `max_range_days` instead of proving catch-up completeness. Import counts and source versions do not prove no transactions were omitted. | Chunk catch-up ranges, track coverage and unresolved failures, reconcile available bank statement/balance evidence, and test long outages, duplicate-looking bookings, pending/booked transitions, and corrections. Add intake/posting backlog controls; do not infer completeness from a successful sync. |

F6/F7 include accounting and e-invoice defects relevant to record accuracy;
they are not claims that every format feature is itself mandated by GoBD.
Unsupported conversions must remain visible and preserve their input evidence.

## Operating requirements still to evidence

- **Retention:** define classes, start dates, extensions, and legal holds.
  AO § 147 generally distinguishes ten-year books, eight-year booking vouchers,
  and six-year other listed records; do not apply one blanket expiry. The FinTS
  SCA cleanup setting is not an accounting retention policy. Initially disable
  accounting disposal; automatic deletion is not needed to reach readiness.
- **Storage and recovery:** demonstrate private storage, protected originals,
  independent anchor permissions, scheduled verification, alert response,
  encrypted backups, key recovery, and a full restore/export exercise.
  `ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED=true` is an assertion, not a test.
  Object lock is a recommended implementation, not a universal statutory
  technology requirement; equivalent effective controls need evidence.
- **Procedures and people:** document capture, review, correction, period close,
  access assignment, incidents, and control execution. Keep the procedure's
  history and the responsible people identifiable. Separation of duties must
  fit the organization; an elaborate approval UI is not automatically required.
- **Deployment identity:** record exact dependencies, configuration, database,
  storage, and application revision. `nemiah/php-fints` currently follows
  `dev-master`; retain the resolved host lock file. Prove non-destructive
  upgrades and historical readability. Never use development database resets
  to migrate real accounting records.

The 2025 GoBD amendment permits retaining only the structured part of a hybrid
e-invoice when the image adds no tax-relevant information. It also permits
content-identical regeneration of outgoing invoice images under its conditions.
This does not permit dropping XML or silently replacing relevant content.
See also the [BMF e-invoice FAQ][einvoice].

## Smallest credible delivery plan

1. **Protect records and access:** fix F1–F5 and F8–F9, add negative regression
   tests, and prove atomicity on one explicitly supported production database.
2. **Make the supported workflows reliable:** fix F6–F7 and F11–F12. A proposed
   first scope is one German company, EUR transactions, ordinary sales/purchase
   invoices, FinTS, reconciliation, and corrections. Customers may be abroad;
   test the supported tax cases. Reject unsupported calculations, not originals.
3. **Make the system inspectable:** complete F10 and establish the operating
   evidence above. If positioned as a subledger, define and test the complete
   handoff to the main ledger. Do not advertise a full accounting replacement
   without the required account/reporting functions.
4. **Validate the claim:** freeze a release, retain the evidence, and commission
   an independent accounting/IT-controls review of the reference installation.
   This is our recommended claim gate, not a statutory certification requirement.
   Reassess affected controls after material changes.

Release acceptance must include adversarial mutation tests, crash/retry and
concurrency tests, end-to-end invoice/correction/settlement scenarios, and an
auditor-style export/restore exercise. Existing [CI](../.github/workflows/tests.yml)
uses SQLite in memory and [fake storage](../tests/Attachments/AttachmentStorageTest.php);
it cannot establish production locking or immutable-storage behavior.

Keep the public documentation to [installation](install.md), [architecture](architecture.md),
[operations](operations.md), and this assessment. Deployment-specific procedures
and evidence belong to the operator; do not recreate an internal roadmap archive
in `docs/`. Only the partial runtime corrections listed above are implemented;
this assessment is not a release approval.

[ao146]: https://www.gesetze-im-internet.de/ao_1977/__146.html
[ao147]: https://www.gesetze-im-internet.de/ao_1977/__147.html
[gobd]: https://ao.bundesfinanzministerium.de/ao/2025/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/inhalt.html
[amendment]: https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html
[einvoice]: https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
