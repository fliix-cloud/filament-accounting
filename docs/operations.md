# Operations

This package supplies accounting controls, not a complete compliance program.
The operator remains responsible for access management, infrastructure,
retention, backups, monitoring, and documented procedures. Installation alone
does not make a system GoBD-compliant or certified.

## Production checklist

- Use separate identities and least-privilege roles for the application,
  migrations, administration, backups, and audit storage.
- Keep attachments and audit anchors on private, versioned or immutable storage.
- Back up the database, files, anchors, keys, configuration, and release identity
  together, and test restoration regularly.
- Run queue workers and the scheduler under supervision.
- Monitor failed imports, SCA sessions, payment submissions, queues, backups,
  and integrity checks.
- Record the deployed package version, commit, migrations, dependencies, and
  relevant configuration for every release.
- Define the Laravel Gates listed in `authorization.abilities`, or provide a
  custom `AccountingAuthorizer`. Authentication alone does not grant access.
- Define retention, legal-hold, disposal, and access-review procedures for the
  applicable jurisdiction.

## Access configuration

The default authorizer denies abilities without an explicitly defined Laravel
Gate. Define the mapped Gates in `authorization.abilities`, including the new
`accounting.invoices.discard-purchase` permission, or supply an
`AccountingAuthorizer` implementation. Each Gate must check the actual actor,
role, and company. Authentication alone grants no accounting permission.
This also applies to workbench installations; the test suite's permissive Gates
are test fixtures only. Configure access explicitly in every development host.

## Audit anchors

Configure a Laravel disk outside the normal database trust boundary:

```dotenv
ACCOUNTING_AUDIT_ANCHOR_DISK=audit-anchors
ACCOUNTING_AUDIT_ANCHOR_PREFIX=accounting/audit-anchors
ACCOUNTING_AUDIT_ANCHOR_REQUIRED=true
ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED=true
```

`ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED` is an operator assertion. Enable it
only after verifying versioning or object lock, independent credentials,
retention, denied overwrite/delete privileges, backup, and restore.

Create anchors on a documented schedule and after important accounting or
release operations:

```bash
php artisan filament-accounting:audit-anchor --json
php artisan filament-accounting:verify --json
```

Both commands return a non-zero exit code on failure. Monitor that result and
retain the versioned JSON output.

`filament-accounting:verify --json` now emits schema version **2**. Update report
consumers accordingly. The per-entity `invoice_evidence` section includes
`intake_count`, `artifact_set_count`, `issues`, and `pending`. It checks retained
purchase originals and outgoing PDF/XML sets, including their audit references,
preservation state, and completed invoice links. Missing or changed evidence
causes a non-zero exit code; storage read failures are also reported as failures.
The command is read-only and requires no authenticated Filament user.

Monitor `pending` separately: preserved but blocked imports and interrupted,
unposted invoice generation produce warnings, while the exit code can remain zero
when integrity is sound. Review them through **Open imports** or **Complete
invoice** in Filament. A missing input that was never preserved may require the
original upload again. Never use verification as a repair or regeneration command.
Retain reports and investigate affected record IDs; schedule this file-reading
check with the real dataset's storage latency and entity-lock duration in mind.

Export and verify portable audit evidence with:

```bash
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --json
```

The export is tamper-evident, not digitally signed. An auditor still needs an
independently obtained anchor or hash to rule out replacement of both database
history and exported evidence.
Without `--dataset`, these commands retain their existing schema version 1 and
cover audit events and anchors only.

To export the linked accounting dataset and its retained files:

```bash
php artisan filament-accounting:audit-export ENTITY_UUID exports/accounting.dataset --dataset --json
php artisan filament-accounting:audit-verify-file exports/accounting.dataset --json
```

The default dataset package uses streaming schema version 2, beginning with
`FILAMENT-ACCOUNTING-DATASET-2` and a newline. The file verifier detects it
automatically and does not query accounting tables. Both export and verification
require PHP's `pdo_sqlite` extension and sufficient protected temporary disk space
for the package and disposable inspection databases, including file chunks.
It includes the 36 explicitly listed tables from
[AccountingDatasetSchema](../src/Export/AccountingDatasetSchema.php), their columns
and relationships, and referenced attachments/intake/artifact files in base64
chunks of at most 64 KiB decoded bytes.
Values retain database strings or null; JSON columns retain their JSON text, so
money and decimal values do not pass through floating-point conversion. IDs join
records inside the package. Host actor identities and custom journal source IDs
remain external references; corresponding host records are not included.

All selected records belong to the requested company, including child records
scoped through their parent. The transfer includes drafts, discarded documents,
bank source versions, settlements, reversals, and open intakes. An input that was
never preserved can have `present: false`; a missing preserved original blocks
export. The `pending` frames distinguish outstanding work from integrity errors.
Do not render or automatically extract stored paths or original filenames from
an untrusted package. File contents remain inert data during verification.

The exporter verifies the existing ledger, invoice evidence, chain, and configured
anchors, then records `accounting_export.prepared` with the dataset SHA-256 digest
under the accounting entity lock. This event means a dataset was prepared; a later
filesystem failure can still prevent delivery. It survives that failure, and
originals are retained. Use a fresh output path for another attempt. Existing output
files are refused; use distinct paths for simultaneous operator exports.

With attested anchor storage configured, add `--anchor` to anchor the committed
export event before writing the package. The report field `export_event_anchored`
states whether an included anchor covers that event. A streaming package requested
with `--anchor` fails verification if its covering anchor is removed. Otherwise retain the reported
dataset hash independently. Even with included anchors, obtain an anchor/hash
through a separate trusted channel to rule out replacement of the entire package.
The dataset commitment protects the transferred snapshot; it does not retroactively
create missing historical evidence for settlements or converted purchase lines.

The command is an operator/console capability, like the existing audit export.
The dataset builder does not supply web authorization: an eventual HTTP/Filament
action must authorize and scope the company before calling it. There is no new UI
or form configuration in this slice. Public-disk exports, absolute/traversal paths,
and enclosing accounting transactions are rejected. Laravel's private visibility
request still depends on the host's real storage configuration.

The allowlist omits bank-connection credentials and protocol state, TAN sessions,
the public institute directory, host tables, unreferenced storage objects, and
logo/template assets. Accounting source payloads and originals remain included.
Attachments targeting unsupported host models cause export failure rather than a
silently incomplete package. Production snapshot consistency under concurrent
writes, deployment-scale resource measurements, independent third-party import,
and full restore exercises remain acceptance work. This is an inspection transfer, not a
database backup, DATEV format, or a complete host-application restore image.

Streaming frames are newline-terminated JSON objects after the magic line:
`header`, `pending`, `table`/`record`, `file`/`chunk`/`file_end`, `dataset_end`,
`event`, and `footer`. The dataset digest covers the exact magic and body bytes
before `dataset_end`; the recorded export event binds that digest. Verification
checks the fixed schema, ownership, references, file hashes and sizes, audit chain,
anchors, footer counts, and complete input consumption. Delivery is read back and
its entire `package_sha256` compared with the prepared stream.

[StreamDatasetExporter](../src/Export/StreamDatasetExporter.php) reads records and
events lazily and originals through streams. Ledger preflight checks entries
incrementally. [StreamDatasetVerifier](../src/Export/StreamDatasetVerifier.php)
uses [DatasetInspection](../src/Export/DatasetInspection.php) to index records,
references, audit events, and file chunks in a disposable SQLite database. Its
optional inspection callback runs only after successful verification. The tests
reconstruct originals and query journal totals and invoice/payment/bank links
after removing source files and audit entries, without querying the host database.
This establishes isolated inspection using this implementation, not a production
restore or independent validation by another implementation.

Frames are limited to 64 MiB each. Working memory still depends on the largest
individual row/invoice and the included anchor list; streaming does not guarantee
a fixed memory ceiling for every dataset. The regression fixture transfers more
than 48 MiB of originals in a package over 64 MiB with less than 24 MiB additional
PHP memory. Measure temporary storage, runtime, and entity-lock duration on the
deployment. Export preparation and evidence capture use separate entity-locked
transactions; consistency with concurrent production writers remains to be proven.

For compatibility, `--dataset --legacy-json` produces the earlier in-memory
`format: filament-accounting-dataset`, schema version 1 package. The verifier
continues to accept it. Audit-only exports remain unchanged. The `--json` option
controls the command report (version 1), whose nested dataset report identifies
version 2 for streaming; it does not select the package format.

Every ledger posting stores a versioned journal snapshot and digest inside its
audit event. `filament-accounting:verify` compares the stored journal against
that evidence, including missing records and altered historical account/period
values. The generic CSV exporter checks the entity's ledger, chain, and configured
anchors before exporting the verified historical values; corruption outside the
requested date range also blocks the export. The scheduled check now also verifies
invoice originals and outgoing render evidence. Complete converted purchase-line
and settlement snapshots are still missing.

These checks detect journal changes; they do not prevent privileged SQL writes.
Independent anchors remain necessary to detect a coordinated rewrite of the
journal, snapshot, and local audit hashes. Run them frequently enough for the
operator's required detection window.

## Retention and recovery

Do not cascade-delete issued documents, original attachments, posted journals,
or audit evidence. Corrections use reversals. Closing a period blocks further
posting; reopening requires authorization, a reason, and an audit event.

Purchase drafts are now **discarded**, not deleted. The draft, positions, and
original files stay available; the audit event records the actor and reason.
Discarding does not cancel the supplier's invoice. The legacy
`DeletePurchaseInvoiceDraft::handle($document, $reason)` service now requires
a reason and retains evidence. Discarded drafts cannot be edited or posted.
No automatic restoration or disposal workflow is provided yet.

New attachment objects have owner-scoped paths with a unique filename per write
attempt. Identical content on different records or under different source types
does not share a new storage object. Retries verify the existing object's hash;
missing or changed bytes fail instead of being silently replaced. Investigate
these failures and restore verified originals through the operator's recovery
procedure before retrying.

Storage writes and database commits are not atomic. Failed verification,
metadata saves, or an enclosing transaction rollback can leave retained objects
without attachment rows. The service deliberately does not delete these files.
Preserve them for investigation; there is no automatic orphan disposal for general
attachment writes. Purchase intake now has the separate inventory described below.
UUID paths and existence checks do not establish
storage immutability or atomic create-only behavior; independently enforced
storage permissions/versioning remain required. Concurrent first uploads may
create duplicate records and objects. Previously stored paths remain readable.

Purchase imports authorize the actor and company, then commit an intake manifest
before writing input files. Paths, names, hashes, sizes, roles, and actor identity
remain discoverable even if processing fails before creating a draft. Each file
is verified before preservation is recorded; only then does parsing start.
PDF, standalone XML, and PDF with companion XML are supported. Invalid accepted
inputs are retained privately as inert bytes and cannot be booked.

In purchase invoices, **Open imports** lists incomplete processing, including
intakes with no document. Verified originals can be downloaded as attachments;
they are not rendered inline. Use **Process again** for preserved interrupted
processing. For incomplete initial preservation, upload the same files again.
Changed or missing previously preserved bytes require investigation and verified
restoration; retry never replaces them. Corrected input creates a separate intake.

Supplier/draft/attachment-reference creation and intake completion share the
accounting connection's transaction. New business records roll back on failure;
the previously committed intake survives. Correlated audit events record attempt
starts and outcomes. If an outcome cannot be saved, monitoring must investigate
the retained start event and open intake. The original processing error is retained.

Call `ImportPurchaseInvoice::handle(...)` or `resume($intake)` outside an enclosing
accounting transaction. The service rejects outer transactions before accepting
input, because their rollback would erase preservation evidence. The Filament
upload page and retry action disable their outer transactions. Integrations must
respect the same boundary. Two-connection SQLite regression tests verify rollback
isolation; production concurrency and storage controls still need validation.

Outgoing invoices commit an authoritative artifact set before writing files. It
contains the exact PDF/XML bytes, render-source snapshot, renderer metadata,
planned paths and hashes, with a digest in the preparation audit event. Include
`accounting_invoice_artifact_sets` and its staged contents in backup, retention,
and restore procedures. The contents remain stored after completion.

For interrupted outgoing invoices, use **Complete invoice** in the sales list or
invoice view. It finishes required files and posting using the existing number and
staged bytes, including after renderer/configuration changes. Missing attachment
references can be reconstructed from a verified set; existing objects are reused.
Previously preserved files that disappear or change require investigation and
verified restoration. The application does not silently replace them. Missing sets
with surviving preparation evidence and ambiguous references block completion.

Direct artifact generation and issuance with required artifacts must run outside
an enclosing accounting transaction. The Filament issue/completion actions disable
their outer transactions. Posting verifies required or existing sets before it
proceeds. Model guards protect generated attachments and staged sets; raw SQL,
privileged storage changes, and coordinated evidence rewrites require independent
controls. Scheduled verification and portable export do not yet cover all artifact
set relationships; full-chain and external-anchor checks remain necessary.

The DEV base migration adds `accounting_invoice_artifact_sets`. Existing generated
attachments without a set are not automatically accepted as authoritative history.
Rebuild disposable development databases only; no production backfill is supplied.

The base DEV migration adds `accounting_purchase_invoice_intakes`. Rebuild only
disposable development fixtures. Existing imports are not automatically assigned
an intake history; no production migration or evidence backfill is provided.
Extraction success is not full e-invoice conformance. Source-total mismatch blocks
conversion while preserving the original, but full format/business-rule validation
and all tax/rounding cases remain open. See [GoBD readiness](gobd.md).

Model guards protect normal Eloquent mutations. Query-builder writes, raw SQL,
privileged database access, and storage deletion still require additional
controls; do not treat these guards as database-level immutability.

The package does not determine statutory retention periods or prove storage
immutability through Laravel's filesystem API. Operators must configure and
evidence those controls in the deployment environment.

## Release checks

Run the repository quality gate before release:

```bash
composer check
```

There are no production installations yet. Schema changes are made directly in
the base migrations: rebuild **disposable DEV databases only** and verify the
fresh installation. No legacy backfill fabricates evidence for old postings.
Journal snapshots are mandatory for verification of posted entries.

```bash
php artisan migrate:fresh --seed
php artisan filament-accounting:verify
```

The generic journal CSV export is not a DATEV export and is not a complete
machine-readable audit export of every stored relation.
