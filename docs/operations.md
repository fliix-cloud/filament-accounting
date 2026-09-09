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

Export and verify portable audit evidence with:

```bash
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --json
```

The export is tamper-evident, not digitally signed. An auditor still needs an
independently obtained anchor or hash to rule out replacement of both database
history and exported evidence.

Every ledger posting stores a versioned journal snapshot and digest inside its
audit event. `filament-accounting:verify` compares the stored journal against
that evidence, including missing records and altered historical account/period
values. The generic CSV exporter checks the entity's ledger, chain, and configured
anchors before exporting the verified historical values; corruption outside the
requested date range also blocks the export. Documents and settlements do not
yet have equivalent content verification.

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
