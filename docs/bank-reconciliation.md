# Bank reconciliation

`BankStatementLine` is the canonical product bank transaction. FinTS writes
directly through `UnifiedBankTransactionImporter`. Stable identity:

```text
legal_entity_id + bank_account_id + external_id
```

`source = fints` is internal provenance, not a public extension point. Import is
idempotent. Each material source state is retained in
`BankTransactionSourceVersion`. Posted reconciliations are not silently rewritten
when amount, currency, or identity later changes; the line is flagged for review.

## Finalization

`FinalizeReconciliation` locks the statement line and open items, then:

1. Verifies entity isolation
2. Requires booked status unless a documented exception reason is supplied
3. Requires split sum == signed statement amount
4. Posts **one** balanced journal
5. Creates settlements
6. Marks the reconciliation posted
7. Dispatches `ReconciliationFinalized` after commit

Suggestions are assistive: end-to-end ID, document number in purpose, amount,
open amount, IBAN, normalized name, date proximity, recurring purpose, and
confirmed local rules. A rule is stored only after the user finalizes a match,
can be disabled or deleted, and contributes an explicit `learned_rule` reason.
Suggestions never auto-post and never cross Legal Entities.

Ambiguous equal top scores are marked `ambiguous`.

## Direct assignment versus split

The database stores one or more allocation rows for every reconciliation, but the UI deliberately distinguishes two user operations:

- **Direct assignment** assigns the complete statement line to one invoice, bill, posting rule, or ledger target. A payment smaller than an invoice's open amount is a partial settlement and remains a direct assignment. The assign confirmation and the bank-transaction table warn when the transaction amount does not equal the open invoice remaining.

The standalone Reconciliation page is the assign/split workspace opened from a bank transaction. It is not registered in navigation.
- **Split transaction** requires at least two allocations. Use it when one transfer pays several invoices or combines an invoice settlement with a fee, discount, transfer, or other explicit posting purpose.

Several independent payments settling the same invoice are several direct assignments over time, not a split. Allocation amounts are signed and must sum exactly to the signed statement amount.

Open-item targets must belong to the current Legal Entity and use the statement currency. Posting-rule versions and ledger accounts are validated against the same Legal Entity before any journal is posted.
