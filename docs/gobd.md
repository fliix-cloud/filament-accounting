# GoBD readiness

Reviewed: 5 September 2026, [commit 99b2218](https://github.com/fliix-cloud/filament-accounting/tree/99b221895511d8c42c1228b36bd3e9f75c0d3e39).

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

Updated: 5 September 2026. The table below tracks changes after the reviewed
baseline; the detailed findings retain that baseline as their reference.
**No finding is fully closed and the compliance verdict is unchanged.**

| Findings | Implemented in this change | Still required |
| --- | --- | --- |
| F1 / F3 | Purchase draft disposal now retains the document, lines, PDF/XML, and an actor/reason audit event. It requires a dedicated permission, current company scope, and a locked persisted draft. UI offers “Discard draft”; physical deletion is disabled. | Preserve failed/rejected imports before parsing; complete intake history and recovery workflows. |
| F2 / F4 | Original attachment metadata and original-file model deletion are guarded. Documents reject final-state downgrades and identity changes; lines reject reparenting and consult stored parent state. Stale journal models cannot edit posted data. | Bulk/SQL protection, concurrent mutation evidence, business snapshots bound to audit hashes, and controlled correction workflows. |
| F3 | Undefined Gates now deny access; the provider no longer creates permissive fallback Gates. Tests explicitly configure fixture permissions; hosts must configure their own Gates. | Complete the authorization audit of all public mutation paths and integrations. |
| F5 | Closing cannot weaken a hard lock. Reopening requires a separate permission and non-blank reason. Both record before/after state, use the accounting connection, and lock entity before period. Repeated close is idempotent. | Production database concurrency tests and protection against direct period-model/SQL changes. |
| F6 / F9 | Posting reloads persisted state and accepts only issued sales invoices or received purchase invoices. Ledger posting/reversal and changed document/period services use the accounting connection. | Currency/discount/tax correctness; connection consistency in the remaining services and cross-connection rollback tests. |

Regression coverage: [document protection](../tests/Documents/RecordProtectionTest.php),
[ledger/period protection](../tests/Ledger/RecordProtectionTest.php),
[authorization](../tests/Authorization/DefaultAccountingAuthorizerTest.php), and
the [Filament discard workflow](../tests/Filament/InvoiceLayoutTest.php).
[CI for implementation commit 8560ff7](https://github.com/fliix-cloud/filament-accounting/actions/runs/33946659939)
passed all 162 tests on PHP 8.3/8.4/8.5, PHPStan, Pint, and Composer validation
(1,735 assertions on PHP 8.3). Local PHP/Composer execution remains unavailable.
This proves the covered regressions, not production concurrency or storage
immutability. F7–F8 and F10–F12 remain open; see the [upgrade notes](operations.md).

## Existing foundation

| Area | Evidence in the reviewed code | Assessment |
| --- | --- | --- |
| Ledger | [FirstPartyLedgerEngine](../src/Ledger/FirstPartyLedgerEngine.php), [ledger tests](../tests/Ledger/LedgerEngineTest.php): balance checks, numbering, idempotency, period locks, linked reversals | Useful foundation; see F2–F6 |
| Documents and tax | Party/company snapshots, confirmed expense categories, [TaxRuleVersion](../src/Models/TaxRuleVersion.php) reference/overlap checks, [invoice tests](../tests/Documents/InvoiceFlowTest.php) | Partial lifecycle protection |
| Banking and reconciliation | Source versions, booked-only finalization, exact splits and settlements; [import tests](../tests/Banking/UnifiedBankTransactionImporterTest.php), [reconciliation tests](../tests/Reconciliation/ReconciliationTest.php), [payment safety tests](../tests/Banking/FinTs/PaymentSubmissionSafetyTest.php) | Good mechanisms; not proof of complete bank records |
| Audit and files | Canonical event chain, external anchors, offline evidence verification, SHA-256 checks on attachment reads; [audit tests](../tests/Audit/AuditAnchorTest.php), [attachment tests](../tests/Attachments/AttachmentStorageTest.php) | Detects specific failures, not all business-data changes |
| Quality checks | [Baseline CI](https://github.com/fliix-cloud/filament-accounting/actions/runs/33943471032): PHPUnit on PHP 8.3/8.4/8.5, PHPStan, Pint, Composer validation passed | Existing suite is green; compliance gaps remain |

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

Keep the public documentation to [architecture](architecture.md),
[operations](operations.md), and this assessment. Deployment-specific procedures
and evidence belong to the operator; do not recreate an internal roadmap archive
in `docs/`. Only the partial runtime corrections listed above are implemented;
this assessment is not a release approval.

[ao146]: https://www.gesetze-im-internet.de/ao_1977/__146.html
[ao147]: https://www.gesetze-im-internet.de/ao_1977/__147.html
[gobd]: https://ao.bundesfinanzministerium.de/ao/2025/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/inhalt.html
[amendment]: https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html
[einvoice]: https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
