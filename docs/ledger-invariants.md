# Ledger invariants

Enforced by `FirstPartyLedgerEngine`:

- At least two lines
- At most one of debit/credit non-zero per line
- No zero posted lines
- Base-currency debits equal credits exactly
- Hard-closed periods reject posting
- Idempotent on `(legal_entity_id, idempotency_key)`
- Database transaction and period row lock
- Reverse creates a linked reversing entry; the original stays posted and immutable

Sequence numbers are unique per legal entity. Historical account/tax/rule meaning is preserved by versioned references on posted lines.
