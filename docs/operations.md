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
- Define retention, legal-hold, disposal, and access-review procedures for the
  applicable jurisdiction.

## Access configuration

The default authorizer denies abilities without an explicitly defined Laravel
Gate. Define the mapped Gates in `authorization.abilities`, including the new
`accounting.invoices.discard-purchase` permission, or supply an
`AccountingAuthorizer` implementation. Each Gate must check the actual actor,
role, and company. Authentication alone grants no accounting permission.
This also applies to workbench installations; the test suite's permissive Gates
are test fixtures only. Existing hosts must configure access before upgrading.

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

For pre-release schema changes, rebuild development databases and verify the
fresh installation:

```bash
php artisan migrate:fresh --seed
php artisan filament-accounting:verify
```

The generic journal CSV export is not a DATEV export and is not a complete
machine-readable audit export of every stored relation.
