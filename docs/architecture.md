# Architecture

`filament-accounting` is one Laravel package with one service provider, one
Filament plugin, and one accounting boundary. `nemiah/php-fints` remains a
framework-independent protocol dependency; all application behavior lives in
this repository.

## Scope

The package currently provides:

- customers, suppliers, catalog items, and sales and purchase invoices;
- a first-party double-entry ledger, open items, periods, and reversals;
- versioned tax and posting rules with a Germany-first profile;
- FinTS account, balance, and transaction synchronization;
- SEPA transfers, direct debits, mandates, and SCA workflows;
- bank reconciliation with direct assignments, partial payments, and splits;
- invoice attachments, structured e-invoices, and audit evidence.

Fixed assets, payroll, cash-register/TSE workflows, consolidation, and complete
foreign tax advice are outside the current scope.

## Boundaries

`LegalEntity` is the reporting and integrity boundary. The default resolver
expects exactly one company per application instance. The host resolves the
current actor and authorization separately; request data never selects the
company. Queue jobs carry scalar identifiers and activate the trusted context
before loading records.

`AccountingBankAccount` and `BankStatementLine` are the canonical bank models.
FinTS imports directly into them. Material source changes create append-only
`BankTransactionSourceVersion` records instead of silently changing posted
accounting data.

Core business rules live in services and contracts. Filament resources provide
the UI but are not the security or accounting boundary.

## Accounting rules

- Money uses integer minor units and exact decimal conversion, never floats.
- A posted journal has at least two non-zero lines and balanced debits/credits.
- Posting is idempotent per Legal Entity and idempotency key.
- Hard-closed periods reject new postings.
- Posted journals and issued documents are immutable.
- Corrections create linked reversals; they do not edit accounting history.
- Document payment state is derived from open items and active settlements.
- Tax and posting rules are versioned by effective date.

Purchase invoices start with a PDF upload. A separate XML e-invoice may be
attached for structured import. The user confirms the business category before
registration; the package resolves the internal ledger account.

## Reconciliation

A direct assignment consumes one complete bank transaction, even when it only
partially settles an invoice. A split is required when one transaction targets
two or more invoices, categories, or ledger purposes. Allocation amounts are
signed, currency-matched, and must equal the transaction exactly.

`FinalizeReconciliation` locks the relevant records, validates ownership and
amounts, posts one balanced journal, creates settlements, and marks the result
as posted in one database transaction. Reversals restore the accounting state
without deleting history.

Suggestions are deterministic and explainable. Confirmed local learning rules
can improve later rankings, but suggestions never post automatically and never
cross Legal Entities.

## E-invoices and security

Structured invoices use `horstoeko/zugferd`. Generation is based on the issued
document snapshot. XML and PDF checks run in PHP without Java or remote
validation services. These checks support validation but do not constitute an
independent conformity certification.

Attachments use a private Laravel disk and content-based MIME detection.
Credentials, dialog state, SCA data, and resumable payment state are encrypted
or redacted. FinTS endpoints require HTTPS by default, and ambiguous payment
submissions are not retried automatically.

The package records critical actions in a per-company SHA-256 audit chain.
Journal posting captures the complete persisted journal and its lines in a
versioned, hashed audit payload. Account and period snapshots preserve historical
export values. Verification detects changed, missing, and unsealed postings;
CSV export refuses failed ledger, audit-chain, or configured-anchor checks.
External anchors make later manipulation detectable when stored outside the
application's normal database and permission boundary.

## Extension points

Host applications may replace the documented contracts for ownership, actor
resolution, tenancy activation, authorization, compliance profiles, audit
anchor storage, accounting export, and e-invoice handling. The first-party
ledger remains behind `LedgerEngine`.
