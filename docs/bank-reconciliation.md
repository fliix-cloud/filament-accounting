# Bank reconciliation

`BankStatementLine` is the accounting copy of an external bank transaction. Unique identity:

```text
legal_entity_id + driver_key + external_id
```

Import is idempotent. Posted reconciliations are not silently rewritten when the source amount/currency/identity later changes; the line is flagged for review.

## Finalization

`FinalizeReconciliation` locks the statement line and open items, then:

1. Verifies entity isolation
2. Requires booked status unless a documented exception reason is supplied
3. Requires split sum == signed statement amount
4. Posts **one** balanced journal
5. Creates settlements
6. Marks the reconciliation posted
7. Dispatches `ReconciliationFinalized` after commit

Suggestions (`SuggestReconciliationMatches`) are assistive: end-to-end ID, document number in purpose, amount, IBAN, name, date proximity, direction. They never auto-post and never cross legal entities.

Ambiguous equal top scores are marked `ambiguous`.
