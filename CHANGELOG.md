# Changelog

## Unreleased

- Store customer bank accounts and SEPA mandate references on the customer (one mandate per IBAN).
- Hide the empty Reconciliation navigation page; assignment still opens from a bank transaction.
- Warn on 1:1 assignment when the bank amount does not match the invoice remaining, and show a match/mismatch badge on assigned transactions.

- Distinguish direct transaction assignment from true multi-target splitting in the Filament workflow.
- Validate reconciliation target type, currency, Legal Entity, and version before posting.
- Add bidirectional navigation between invoices and assigned bank transactions.
- Add an explicit provider-neutral bank source-link registry.

- Initial first-party ledger, documents, open items, bank reconciliation, German compliance profile, Filament v5 plugin, and Testbench workbench.
