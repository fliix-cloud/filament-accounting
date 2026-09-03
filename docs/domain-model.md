# Domain model

| Concept | Model | Notes |
| --- | --- | --- |
| Firma | `LegalEntity` | Accounting boundary |
| Kunde / Lieferant | `Party` | Role flags; shared model, separate Filament resources |
| Gegenpartei-Bankkonto | `PartyBankAccount` | IBAN/BIC of a customer or supplier; referenced by mandates |
| Lastschriftmandat | `Banking\FinTs\Models\DirectDebitMandate` | Authoritative, Legal Entity/party/account scoped; used identity is immutable and changes create a successor |
| Beleg | `Document` + `DocumentLine` | Type + direction; commercial snapshot after issue |
| Offener Posten | `OpenItem` | Remaining = original − active settlements |
| Buchungssatz | `JournalEntry` + `JournalLine` | Posted records are immutable |
| Steuerfall | `PostingRule` + `PostingRuleVersion` | Versioned recipe |
| Bankverbindung | `Banking\FinTs\Models\BankConnection` | Directly owned by `LegalEntity`; credentials/dialog state encrypted |
| Bankkonto | `AccountingBankAccount` | Canonical account for FinTS, balance, activation and automatic ledger link |
| Bankumsatz | `BankStatementLine` | Canonical transaction (“Umsatz”); no copied FinTS transaction model |
| Bankquellversion | `BankTransactionSourceVersion` | Append-only normalized/raw source state and SHA-256 hash |
| Zuordnung | `Reconciliation` + `ReconciliationSplit` | Exact signed split sum |
| Zahlungsausgleich | `Settlement` | Clears an open item via a payment journal |
| Lokale Lernregel | `ReconciliationLearningRule` | Created only after confirmation; explainable, editable/deletable, never auto-posting |

Payment status on documents is **derived** from remaining vs original. It is not a stored truth flag.

Posted journals and issued commercial document fields reject update/delete (`PostedRecordImmutableException`). Corrections are linked reversals, not soft deletes.
