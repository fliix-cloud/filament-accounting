# Operations and retention

- Posted journals, issued documents, and original attachments are retained; do not cascade-delete accounting truth.
- Period hard-close (Festschreibung) blocks further posting. Reopen requires authorization, a reason, and an audit event.
- `filament-accounting:verify` checks posted journals for balance and minimum line count.
- Optional queues: bank-feed import, integrity checks. Never unattended payment submission.
- Hosts are responsible for backups, access control, and statutory retention periods. The package stores hashes, timestamps, actors, and immutable originals to support those procedures.
