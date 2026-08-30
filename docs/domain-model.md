# Domain model

| Concept | Model | Notes |
| --- | --- | --- |
| Firma | `LegalEntity` | Accounting boundary |
| Kunde / Lieferant | `Party` | Role flags; shared model, separate Filament resources |
| Beleg | `Document` + `DocumentLine` | Type + direction; commercial snapshot after issue |
| Offener Posten | `OpenItem` | Remaining = original − active settlements |
| Buchungssatz | `JournalEntry` + `JournalLine` | Posted records are immutable |
| Steuerfall | `PostingRule` + `PostingRuleVersion` | Versioned recipe |
| Bankumsatz | `BankStatementLine` | Canonical copy of an external transaction |
| Zuordnung | `Reconciliation` + `ReconciliationSplit` | Exact signed split sum |
| Zahlungsausgleich | `Settlement` | Clears an open item via a payment journal |

Payment status on documents is **derived** from remaining vs original. It is not a stored truth flag.

Posted journals and issued commercial document fields reject update/delete (`PostedRecordImmutableException`). Corrections are linked reversals, not soft deletes.
