# Operations and retention

- Posted journals, issued documents, and original attachments are retained; do not cascade-delete accounting truth.
- Period hard-close (Festschreibung) blocks further posting. Reopen requires authorization, a reason, and an audit event.
- `filament-accounting:verify` checks posted journals, canonical audit chains, database chain heads, and configured external anchors; `--json` provides schema-versioned monitoring output.
- `filament-accounting:audit-anchor` writes idempotent, chained heads to separately controlled storage. Production use requires the controls and attestation described in [Audit-chain integrity and external anchors](audit-integrity.md).
- `filament-accounting:audit-export` creates a portable evidence document; `filament-accounting:audit-verify-file` verifies that document without reading accounting tables.
- Optional queues: bank-feed import, integrity checks. Never unattended payment submission.
- Hosts are responsible for backups, access control, and statutory retention periods. The package stores hashes, timestamps, actors, and immutable originals to support those procedures.
