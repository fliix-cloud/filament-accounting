# Audit-chain integrity and external anchors

The package records audit events in a canonical SHA-256 chain per legal entity and keeps the current chain head in the application database. That database head detects accidental damage, but it is not an independent trust boundary. `filament-accounting:audit-anchor` therefore writes periodic chain heads to a separately configured Laravel filesystem disk.

This mechanism is tamper-evident, not a certification and not a substitute for storage governance. A production reference deployment must use versioned or object-locked storage with credentials and retention controls independent from the normal database role. A local disk is suitable only for development or where equivalent technical and organizational controls are separately evidenced.

## Configuration

```dotenv
ACCOUNTING_AUDIT_ANCHOR_DISK=audit-anchors
ACCOUNTING_AUDIT_ANCHOR_PREFIX=accounting/audit-anchors
ACCOUNTING_AUDIT_ANCHOR_REQUIRED=true
ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED=true
```

`ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED` is an explicit operator assertion. Laravel's filesystem API cannot prove object lock, versioning, retention, separate credentials, or denied delete permissions. The anchor command fails closed until this assertion is enabled. The assertion must only be enabled after the host has retained evidence for those capabilities under the [host responsibility contract](compliance/HOST_RESPONSIBILITY_CONTRACT.md).

The storage identity used by the anchor writer should have create/read access to the configured prefix but no ordinary overwrite, version-delete, retention-bypass, or object-lock-administration permission. Backup and restore must include both the database and the anchor store.

## Operation

Write a chain head for every legal entity:

```bash
php artisan filament-accounting:audit-anchor --json
```

Limit the operation to one entity by numeric ID or UUID:

```bash
php artisan filament-accounting:audit-anchor --legal-entity=ENTITY_UUID --json
```

The operation is idempotent while the audit-chain head is unchanged. A later head creates a new anchor whose `previous_anchor_hash` references the preceding anchor. Objects use this layout:

```text
{prefix}/{legal-entity-uuid}/{20-digit-sequence}-{anchor-hash}.json
```

Run anchoring on a risk-based schedule and after important period/release operations. The host must document the chosen interval because it determines the maximum unanchored tail. Monitor a non-zero exit code and retain the JSON output as operational evidence.

Verify ledger, database audit chain, and all available external anchors with:

```bash
php artisan filament-accounting:verify --json
```

Both JSON commands expose `schema_version: 1` and return exit code `0` only when their complete report is successful. `ACCOUNTING_AUDIT_ANCHOR_REQUIRED=true` makes a missing anchor an integrity failure. Existing anchors are checked even when the setting is false.

Export a portable evidence document to a relative path on a Laravel filesystem disk and verify it without reading the accounting database:

```bash
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --disk=local --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --disk=local --json
```

The exporter refuses to overwrite an existing file and refuses to export a chain or anchor set that is already invalid. The evidence document contains the exact hash-relevant audit attributes, database chain head, available external anchors, versioned algorithms/policy metadata, and a canonical SHA-256 document hash. `audit-verify-file` uses the same data-source-neutral chain and anchor validators as online verification and does not query legal-entity, audit-event, or chain-head tables.

## Verification scope and residual risks

Verification checks anchor schema and canonicalization versions, anchor hashes, legal-entity identity, monotonically increasing sequence, predecessor links, referenced audit-event hashes, storage attestation, and required-anchor presence. It also detects a database history that was internally re-hashed after an earlier external anchor.

The package cannot prevent or independently observe deletion of the newest external object by a principal that can bypass storage retention. Object lock/versioning, provider audit logs, separate permissions, alerting, immutable backup, and restore tests are therefore required host controls.

Offline verification proves the internal consistency of the supplied document against the supplied external anchors. The evidence document and its self-contained hash are not a digital signature: an auditor must obtain the anchor object or its hash through the independently controlled storage/audit channel to rule out wholesale replacement of both history and bundle. Key-backed signatures and external timestamping are optional future hardening, not properties of schema version 1.
