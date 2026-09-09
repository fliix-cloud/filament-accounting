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
were not inspected. Findings below are source-level observations; their
regression scenarios have not been executed locally because PHP and Composer
are unavailable in the review environment.

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

Updated: 9 September 2026. The table below tracks changes after the reviewed
baseline; the detailed findings retain that baseline as their reference.
**No finding is fully closed and the compliance verdict is unchanged.**

| Findings | Implemented in this change | Still required |
| --- | --- | --- |
| F11 / F9 | New attachment writes use owner-scoped, per-attempt object paths. Within `StoreAttachment`, failed writes, verification, and metadata saves no longer delete files. Its retries verify retained bytes and reject missing or changed originals without replacing them. Attachment metadata uses the accounting entity's transaction connection. | Durable intake/recovery inventory, concurrent-request deduplication, production storage controls, and the authoritative issued-artifact/retry workflow remain open. Retained objects can lack metadata after failure or rollback. |
| F1 / F3 / F7 / F11 | Import and artifact-generation failure paths retain saved files and metadata and propagate the original exception. Imports authorize before supplier creation or retry lookup, include supplied XML in identity, and verify expected original hashes before retry success and receipt. Artifact retries verify existing bytes, reject partial/ambiguous pairs, and retain a complete pair across renderer upgrades. | Intake before parsing, durable processing/attempt history, safe recovery of incomplete pairs and orphan objects, concurrent processing, and independently bound document/artifact evidence. No new Filament fields were added. |
| F1 / F3 | Purchase draft disposal now retains the document, lines, PDF/XML, and an actor/reason audit event. It requires a dedicated permission, current company scope, and a locked persisted draft. UI offers “Discard draft”; physical deletion is disabled. | Preserve failed/rejected imports before parsing; complete intake history and recovery workflows. |
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

## Next work package: preserve originals without complicating Filament

Targeted continuation: 9 September 2026. The first slice below is implemented;
the intake and recovery work remains planned. This is not a new repository-wide
audit. Graph discovery and coverage
checks failed with `Transport closed`; the linked files were read directly.
Local regression results are recorded above.

### Implemented first slice: failure and retry protection (F1 / F2 / F7 / F11)

The preceding attachment-service improvements left these caller-level gaps,
now corrected in this slice:

- [ImportPurchaseInvoice](../src/Services/ImportPurchaseInvoice.php) no longer
  deletes objects, attachment rows, draft lines, or suppliers on later failure.
  This removes the storage deletion that ran before the model deletion guard
  and could mask the original error.
- Import identity includes supplied XML; existing drafts are checked against
  the input and expected PDF/XML hashes. [VerifyPurchaseInvoiceOriginals](../src/Services/VerifyPurchaseInvoiceOriginals.php)
  requires exactly one matching attachment per expected role and verifies its
  bytes. Receipt runs the same check. Incomplete imports remain blocked, including
  when a PDF row exists but the XML metadata save failed.
- [GenerateInvoiceArtifacts](../src/Services/GenerateInvoiceArtifacts.php) retains
  XML when PDF storage fails, verifies existing files before returning, rejects
  partial or ambiguous pairs, and reuses a complete pair across renderer upgrades.

**Boundary:** this is fail-closed protection, not automatic recovery. Retained
drafts remain available, but durable intake/attempt states, an incomplete-operation
filter, and a recovery action are still absent. Metadata-save failures can leave
objects without rows. A generated pair with no surviving rows cannot yet be
distinguished from first generation. Generated-file model deletion, raw database
changes, concurrent generation, and independently anchored artifact identity
still need protection; this slice does not establish an authoritative issuance
manifest. Import hash metadata is not independently anchored either.

The expected structured hash is new import metadata; older structured DEV drafts
without it fail verification. PDF-plus-XML imports also use a new identity key.
Rebuild disposable DEV fixtures rather than inventing historical evidence; no
production upgrade/backfill is supplied. This slice adds no Filament form fields.

### Next: durable intake before parsing (F1 / F3 / F7 / F9 / F11)

Create a company-scoped intake record independent of the booking draft. Authorize
the actor and company before accepting input. Record the intake identity, actor,
time, expected object paths, original filenames, sizes and hashes, processing
attempts, error state, and eventual document link. Persist the intake intent
before writing objects; verify and record preservation before parsing or creating
supplier/document data. An interrupted write must remain discoverable even when
attachment metadata was not saved. Do not put this evidence solely inside a
transaction that draft creation can roll back.

Accept PDF, standalone XML, and PDF with companion XML. Import identity must
include the company and all supplied contents with their roles; enforce unique
processing under concurrent requests. Preserve each attempt's history and verify
existing objects on retry. Recovery must finish the same intake without duplicate
drafts or replacement of retained originals.

[StoreAttachment](../src/Services/StoreAttachment.php) currently checks MIME and
parses XML before writing, so calling it earlier is insufficient for malformed
input. Provide a private raw-intake path with authorization and size limits;
retain rejected content as inert bytes, never render it inline or resolve XML
entities. Distinguish upload rejection before acceptance from a preserved intake
that failed parsing. Never claim successful preservation after a storage failure.

Separate extraction success, format/business-rule validation, and eligibility
for booking. Reconcile source totals with calculated totals and block unsupported
conversions while keeping the original and an actionable explanation.

Update [PurchaseInvoiceUploadTest](../tests/Documents/PurchaseInvoiceUploadTest.php):
it currently requires standalone XML rejection and no retained objects for unsafe
XML. Replace those expectations with accepted standalone invoices and safely
retained, blocked invalid input. Add malformed XML, conflicting embedded/supplied
XML, source-total mismatch, interrupted-write recovery, cross-company access,
concurrent duplicate requests, and accounting-connection rollback scenarios.
Prove locking on the selected production database; SQLite tests alone do not
close the concurrency gate.

### Filament simplicity is an acceptance criterion

The normal purchase flow remains **upload → review → book**. Extend the existing
[purchase upload page](../src/Filament/Resources/PurchaseInvoiceResource/Pages/CreatePurchaseInvoice.php)
to accept PDF or XML in the main field; keep companion XML optional and secondary.
Detect the format automatically. Do not ask users to choose parsers, archive
policies, hash versions, or recovery modes.

Show incomplete intakes within the purchase-invoice area even when no draft
exists. Use a short status and concrete next action, for example “Gespeichert –
Prüfung erforderlich” or “Verarbeitung unterbrochen – erneut versuchen”. Show
“Gespeichert” only after verified preservation. Offer retry only for recoverable
failures; integrity failures require investigation and must block booking.
Keep technical diagnostics and processing history in expandable details.
Successful processing should lead directly to the existing invoice review.

Do not add a separate compliance dashboard, a mandatory approval wizard, or
routine archive settings. Enforce permissions, preservation, deduplication, and
integrity checks in services; hiding a button is not authorization. UI acceptance
must cover standalone XML upload, a failed intake without a draft, an authorized
retry, and a blocked integrity failure with a useful message.

### Following slices

1. Finish the authoritative issued-artifact workflow: retain and verify the
   issued PDF/XML pair across retries and renderer upgrades; expose interrupted
   issuance without requiring users to manage artifact versions (F11).
2. Complete service authorization and accounting-connection consistency, then
   finalized business evidence and controlled corrections (F2–F4 / F8–F9).
3. Complete supported tax cases, bank catch-up completeness, and the related
   exception handling (F6 / F12).
4. Deliver read-only inspection and the complete linked export, followed by
   production concurrency, storage, restore, and operating evidence (F10 and
   release gates below).

This ordering prioritizes a demonstrated original-loss path and its recovery
foundation. It does not downgrade the other open P0 findings or authorize a
readiness claim before all release gates pass.

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
