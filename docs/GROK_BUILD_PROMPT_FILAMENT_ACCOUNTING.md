# Grok Build Prompt — Filament Accounting with FinTS Bridge

> Canonical, implementation-first build specification for a reusable Laravel 13 / Filament v5 accounting package, a clean FinTS integration bridge, and a separate local demonstration application.
>
> Reference date: **2026-08-30**. Re-verify mutable software versions, standards, and legal sources from their primary sources before implementation.

---

## 0. Your role and operating mode

You are the principal engineer responsible for designing, implementing, testing, documenting, and validating this project end to end.

Do not respond with only a proposal, architecture sketch, file list, pseudocode, or partial scaffold. Inspect the real repositories first, create a concrete execution plan, and then implement the complete agreed scope.

Work methodically and take as much time as necessary. Correctness, accounting integrity, package boundaries, tenant isolation, auditability, security, and maintainability are more important than speed.

You must:

1. Inspect the existing `fliix-cloud/filament-fints` repository and its current `master` branch before changing anything.
2. Inspect any existing target repositories before initializing or modifying them.
3. Preserve unrelated and user-authored changes.
4. Produce an explicit implementation plan derived from the real codebase.
5. Implement the plan completely.
6. Test every important invariant and user workflow.
7. Run the complete quality suite in every affected package.
8. Perform a final independent self-review against this specification.
9. Leave no critical TODOs, empty production methods, placeholder implementations, fake integrations, commented-out requirements, or unimplemented acceptance criteria.
10. Clearly report anything that cannot be completed because of a real external blocker.

Do not silently simplify requirements. If a requirement conflicts with verified framework behavior, current law, security, or accounting correctness, document the conflict, choose the safest standards-based implementation, and explain the decision in an ADR.

---

## 1. Mission

Build a reusable, open-source accounting foundation for Laravel 13 and Filament v5 that:

- supports legal entities headquartered in Germany or another country;
- provides German-first accounting and compliance behavior without hard-wiring Germany into the generic domain core;
- manages customers, suppliers, products/services, outgoing invoices, incoming invoices, credit notes, attachments, and open items;
- implements a real double-entry ledger foundation rather than merely assigning labels to transactions;
- imports normalized bank statement lines through pluggable bank-feed drivers;
- reconciles bank statement lines with outgoing invoices, incoming invoices, open items, general ledger postings, fees, transfers, and split allocations;
- integrates cleanly with the existing public `fliix-cloud/filament-fints` package through an explicit bridge package;
- keeps `filament-fints` usable as a standalone banking package with no accounting dependency;
- keeps `filament-accounting` usable internationally with no FinTS dependency;
- uses a small bridge package that depends on both packages and contains only mapping/integration concerns;
- is tenant-safe, auditable, localized in German and English, secure, exact in monetary calculations, and thoroughly tested;
- includes a committed Testbench workbench and a separate untracked Laravel Herd demo application.

The result must be a production-quality v1 foundation. It does not need to implement every conceivable ERP feature, but every feature declared in scope must be real, coherent, tested, and documented.

---

## 2. Product boundary and terminology

### 2.1 Accounting versus preparatory bookkeeping

The package must have a real double-entry journal foundation from day one. Do not build an invoice manager that stores only a `tax_case` string and later attempts to retrofit a ledger.

The initial UI may deliberately hide accounting complexity from normal users, but the persisted result of a finalized accounting operation must be a balanced, immutable journal entry.

The project may document that regulatory compliance also depends on deployment, permissions, backups, retention, and procedural documentation. Do not claim that installing the package alone makes a host application “GoBD certified”.

### 2.2 Canonical terms

Use the following domain terminology consistently in code and documentation:

| User-facing concept | Domain term | Meaning |
| --- | --- | --- |
| Firma / Unternehmen | `LegalEntity` | Accounting and reporting boundary |
| Kunde / Lieferant | `Party` with roles | One party can be customer, supplier, or both |
| Ausgangs-/Eingangsrechnung | `Document` / `Invoice` with direction and type | Commercial document aggregate |
| Offener Posten | `OpenItem` | Receivable or payable awaiting settlement |
| Buchungssatz | `JournalEntry` | Header for one balanced double-entry transaction |
| Buchungszeile | `JournalLine` | Debit or credit line in a journal entry |
| Steuerfall / Steuerkategorie | `PostingRule` / `BookingTemplate` | Versioned user-friendly posting rule |
| Bankumsatz | `BankStatementLine` | Canonical accounting copy of an external bank transaction |
| Zuordnung | `Reconciliation` | Matching and posting a statement line |
| Splittbuchung | `ReconciliationSplit` | Multiple allocations belonging to one statement line |
| Zahlungsausgleich | `Settlement` | Clearing an open item using a payment journal entry |

The German UI may continue to use the familiar word **Steuerfall** where helpful. The domain model must not reduce the concept to a static enum or string.

---

## 3. Mandatory repository and development topology

### 3.1 Existing package

Repository:

```text
https://github.com/fliix-cloud/filament-fints
```

Local path:

```text
C:\Code\filament-fints
```

Branch:

```text
master
```

Existing namespaces and branding must remain:

- Composer/GitHub package: `fliix-cloud/filament-fints`
- Laravel/Filament namespace: `FilamentFints\`
- Upstream FinTS protocol namespace: `Fhp\`

Do not move or rewrite the upstream-compatible `Fhp\` protocol implementation. Preserve its mergeability with `nemiah/phpFinTS`.

### 3.2 New accounting package

Repository/package:

```text
fliix-cloud/filament-accounting
```

Local path:

```text
C:\Code\filament-accounting
```

Namespace:

```text
FilamentAccounting\
```

Default development branch:

```text
master
```

This repository is an installable Composer package, not a full Laravel application.

### 3.3 New FinTS bridge package

Repository/package:

```text
fliix-cloud/filament-accounting-fints
```

Local path:

```text
C:\Code\filament-accounting-fints
```

Namespace:

```text
FilamentAccountingFints\
```

Default development branch:

```text
master
```

This package must require both:

```text
fliix-cloud/filament-accounting
fliix-cloud/filament-fints
```

It must not contain accounting business rules or FinTS protocol behavior. It is an anti-corruption layer that maps one bounded context to the other.

### 3.4 Standalone Herd demo

Create a separate, untracked Laravel application at exactly:

```text
%USERPROFILE%\Herd\filament-accounting-demo
```

Requirements:

- Laravel 13;
- Filament v5;
- panel path `/admin`;
- consumes all three packages through Composer `path` repositories with `symlink: true`;
- uses synthetic demonstration banking data by default;
- contains no real bank credentials, no production secrets, and no copied package source;
- is not committed or pushed to any package repository;
- is reproducible through a PowerShell setup script committed to the accounting package.

Only package repositories and their package-owned files are pushed to GitHub.

### 3.5 Composer path repository configuration

The demo must use the equivalent of:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "C:/Code/filament-accounting",
            "options": { "symlink": true }
        },
        {
            "type": "path",
            "url": "C:/Code/filament-accounting-fints",
            "options": { "symlink": true }
        },
        {
            "type": "path",
            "url": "C:/Code/filament-fints",
            "options": { "symlink": true }
        }
    ]
}
```

Document exact installation commands and verify that the installed vendor paths are symlinks/junctions rather than copied source.

---

## 4. Mandatory package dependency direction

The dependency graph is fixed:

```text
Host Laravel application
├── fliix-cloud/filament-accounting
├── fliix-cloud/filament-fints
└── fliix-cloud/filament-accounting-fints
    ├── depends on filament-accounting
    └── depends on filament-fints
```

Non-negotiable rules:

1. `filament-fints` must never require, reference, or auto-detect `filament-accounting`.
2. `filament-accounting` must never require, reference, or auto-detect `filament-fints`.
3. The bridge may reference both public contracts and models where necessary.
4. Do not use `class_exists()` probing to create hidden optional integrations.
5. Do not inject Eloquent relations dynamically with runtime “magic”.
6. Do not add invoice IDs, tax-case columns, reconciliation columns, or accounting status fields to `fints_bank_transactions`.
7. Do not add FinTS-specific columns or classes to the generic accounting package.
8. Cross-package interaction must go through explicit contracts, DTOs, commands/services, and events.

---

## 5. Inspect and preserve the existing FinTS package

Before implementing anything, inspect at least:

- `README.md`
- `DEVELOPER-GUIDE.md`
- `docs/architecture.md`
- `docs/extending.md`
- `config/filament-fints.php`
- `database/migrations/*`
- `src/FilamentFints/Models/BankTransaction.php`
- `src/FilamentFints/Models/BankAccount.php`
- `src/FilamentFints/Models/BankConnection.php`
- `src/FilamentFints/Services/TransactionSyncService.php`
- `src/FilamentFints/Events/BankTransactionsSynced.php`
- `src/FilamentFints/Filament/Resources/BankTransactionResource.php`
- `src/FilamentFints/FilamentFintsPlugin.php`
- ownership, authorization, tenancy, SCA, migration, and transaction-sync tests.

Preserve the existing architectural decisions:

- `BankConnection` is the FinTS ownership boundary;
- Owner, Actor, and Membership are distinct;
- the owner is resolved from trusted ambient application context;
- membership is authorization context unless intentionally configured as owner;
- queued work carries durable owner identity;
- FinTS operations remain SCA-safe and idempotent;
- transactions are synchronized and de-duplicated by the FinTS package;
- pending transactions can be semantically updated to booked transactions;
- the FinTS package remains independently installable and usable.

Run the FinTS baseline test suite before making changes. Record the baseline result. Run it again after every FinTS change.

---

## 6. Required minimal changes to `filament-fints`

Keep this change set deliberately small and backward compatible.

### 6.1 Stable transaction UUID

Add a non-null, unique UUID to `fints_bank_transactions` through a new migration.

Requirements:

- safely backfill all existing rows;
- use a database-agnostic migration strategy compatible with supported Laravel databases;
- generate UUIDs on model creation;
- preserve the UUID when a pending row is updated to booked;
- retain the current numeric primary key;
- do not silently change existing route keys or URLs unless tests prove the change is backward compatible;
- add unique indexing;
- add model, migration, upgrade, and pending-to-booked tests.

The UUID is the external integration identity. The existing fingerprint remains the bank synchronization and de-duplication identity.

### 6.2 Durable integration event

Keep `BankTransactionsSynced` backward compatible. Do not break its current constructor or semantics.

Introduce a new integration-focused event with a clear name such as:

```text
BankStatementLinesChanged
```

It must carry only durable, serializable identity and synchronization context, including:

- `OwnerReference` or equivalent owner type/id values;
- bank connection UUID or durable ID;
- bank account UUID;
- sync-run UUID;
- synchronization date range when available;
- a timestamp or cursor usable for idempotent rescan.

Requirements:

- dispatch only after successful persistence/commit;
- dispatch even when no new row was inserted but pending/booked state may have changed;
- never treat the old `imported` count as evidence that nothing changed;
- be safe for queued listeners and multi-database tenancy;
- contain no credentials, PIN, TAN, full serialized dialog, or sensitive authentication state;
- add event and serialization tests.

### 6.3 No accounting coupling

The FinTS package must not know whether the integration event has listeners. It must not call Accounting services directly.

### 6.4 Existing raw transaction Resource

Preserve the standalone `BankTransactionResource`.

The existing plugin already supports disabling it through:

```php
FilamentFintsPlugin::make()->transactions(false)
```

Retain and test this behavior. In an accounting-enabled host, the unified Accounting bank reconciliation Resource will replace the raw FinTS transaction Resource.

Do not introduce global Filament component configuration solely to decorate the raw Resource.

---

## 7. Accounting package architecture

### 7.1 Package technology

Use:

- PHP 8.3+;
- Laravel 13 components;
- Filament v5;
- `spatie/laravel-package-tools` or another already-established package pattern consistent with `filament-fints`;
- Testbench with a committed workbench;
- exact-money value objects backed by a mature maintained library such as `brick/money`, after verifying current compatibility and license;
- no JavaScript framework outside the stack already required by Filament/Livewire unless demonstrably necessary.

Do not use floating-point numbers for persisted or calculated monetary amounts.

### 7.2 Recommended source organization

Use cohesive domain modules while keeping one installable package:

```text
src/
├── Accounting/
│   ├── Contracts/
│   ├── Data/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Models/
│   ├── Services/
│   └── Support/
├── Banking/
│   ├── Contracts/
│   ├── Data/
│   ├── Models/
│   └── Services/
├── Compliance/
│   ├── Contracts/
│   ├── Germany/
│   └── Support/
├── Documents/
├── Ledger/
├── Parties/
├── Reconciliation/
├── Filament/
├── Commands/
├── Policies/
└── FilamentAccountingPlugin.php
```

Exact directories may be adjusted to Laravel conventions, but boundaries and dependency direction must remain explicit.

### 7.3 Service layer

Business behavior belongs in focused services/actions, not Filament callbacks, Livewire components, controllers, observers, or model event hooks.

Examples:

- `IssueSalesInvoice`
- `RegisterPurchaseInvoice`
- `PostDocument`
- `CreateJournalEntry`
- `ReverseJournalEntry`
- `CreateOpenItem`
- `ImportBankStatementLines`
- `SuggestReconciliationMatches`
- `FinalizeReconciliation`
- `ReverseReconciliation`
- `CloseAccountingPeriod`
- `ReopenAccountingPeriod` with explicit authorization/audit

Filament Resources orchestrate these services and present results. They do not contain accounting rules.

### 7.4 No hidden model mutation

Do not use broad `$guarded = []` on integrity-critical Accounting models.

Use explicit fillable fields, typed DTOs, domain services, database transactions, row locks where needed, and strict state transitions.

---

## 8. Owner, Actor, Membership, Legal Entity, and tenancy

Preserve the successful FinTS principle:

```text
Owner ≠ Actor ≠ Membership
```

### 8.1 Legal entity as accounting boundary

`LegalEntity` is the Accounting reporting and integrity boundary. Every journal, document, party relationship, bank account, statement line, tax configuration, accounting period, and reconciliation belongs to exactly one Legal Entity.

A host Tenant may represent one Legal Entity or may contain several Legal Entities. Do not assume they are always identical.

### 8.2 Context contracts

Provide contracts equivalent to:

- `AccountingEntityResolver`
- `AccountingActorResolver`
- `AccountingAuthorizer`
- `AccountingTenancyContextActivator`

The entity resolver must derive the current Legal Entity from trusted application context. Never accept it from a request parameter, hidden form input, or query string without authorization and server-side validation.

The actor resolver identifies the authenticated human/system performing the operation.

Membership and roles explain why the actor may access the entity. They are authorization context, not data ownership.

### 8.3 FinTS owner alignment

For the integration demo, configure `filament-fints` so the same `LegalEntity` model owns the relevant `BankConnection`.

The bridge must assert that:

- the FinTS connection owner matches the current Accounting Legal Entity;
- the actor is authorized in both bounded contexts;
- no statement line can be imported or reconciled across entities;
- queued integration work activates tenancy before querying either package.

If host Tenant and Legal Entity differ, use an explicit mapping. Never guess from matching IDs.

### 8.4 Multi-database tenancy

Do not rely on cross-database foreign keys.

All queued jobs/events must carry durable scalar identity needed to activate the correct tenant context before querying tenant-owned tables.

Add tests that demonstrate isolation between two entities using the same numeric IDs in different tenant/database contexts where feasible.

---

## 9. Core data model

Use package-prefixed table names to avoid host collisions. Use UUIDs for public/integration identity and numeric primary keys internally unless a verified reason dictates otherwise.

Exact names may be refined, but the following concepts and invariants are mandatory.

### 9.1 Legal entities

`accounting_legal_entities`:

- numeric primary key;
- UUID unique;
- optional host owner type/id mapping;
- legal name and trading name;
- country code using ISO 3166-1 alpha-2;
- base/reporting currency using ISO 4217;
- locale and timezone;
- fiscal-year start;
- accounting basis/profile;
- VAT/taxation method where applicable;
- default compliance profile key;
- state (`active`, `inactive`, not hard-deleted after use);
- timestamps and auditable configuration history.

Do not use locale as a proxy for tax jurisdiction.

### 9.2 Parties and roles

Use one `Party` aggregate for customers and suppliers.

Tables should support:

- party UUID;
- legal-entity ownership;
- customer and supplier role flags or normalized roles;
- organization/person distinction;
- legal/display names;
- postal addresses;
- country;
- email/phone;
- VAT IDs and tax numbers with type/country metadata;
- payment terms;
- default currency;
- optional external references;
- active/archive state;
- no destructive deletion after being referenced by a posted document.

Expose separate Customer and Supplier Filament navigation/resources through explicit scopes or Filament configurable Resource registrations while reusing the same domain model.

### 9.3 Catalog items

Products and services are optional helpers for outgoing invoice entry.

Support:

- UUID/SKU;
- type (`product`, `service`, or extensible equivalent);
- localized name/description where useful;
- unit;
- default quantity/price;
- default revenue account semantic role/account;
- default tax code;
- active/archive state.

An invoice line must remain valid if the catalog item changes or is archived.

### 9.4 Documents and invoices

Prefer one coherent document aggregate with explicit type and direction rather than duplicated sales/purchase calculation code.

Support at least:

- sales invoice;
- purchase invoice/bill;
- sales credit note/correction;
- purchase credit note/correction;
- draft and issued/received lifecycle;
- cancellation/reversal through a new linked correction, never destructive mutation;
- document number ranges per Legal Entity and document type;
- supplier invoice number and duplicate detection;
- issue/receipt date;
- supply/service date or period;
- due date and payment terms;
- document currency and exchange-rate snapshot;
- net, tax, gross, rounding, paid, and open totals;
- party reference plus immutable party snapshot;
- structured e-invoice metadata;
- original attachment(s);
- created/issued/posted/reversed actor and timestamps;
- clear separation between document status, posting status, and payment status.

Suggested separate statuses:

```text
document_status: draft | issued | received | corrected | cancelled
posting_status: unposted | posted | reversed
payment_status: unpaid | partially_paid | paid | overpaid
```

Do not collapse these into one status enum.

### 9.5 Document lines

Snapshot at least:

- description;
- quantity as exact decimal string;
- unit;
- exact unit price;
- discount/surcharge details;
- net amount;
- tax code and tax-rule version;
- tax rate snapshot;
- tax amount;
- gross amount;
- revenue/expense account mapping;
- optional catalog item ID;
- service period;
- line order.

Changing a catalog item, tax rule, or party after issue must not alter an issued document.

### 9.6 Open items

A posted sales invoice creates a receivable open item. A posted purchase invoice creates a payable open item.

Open items must support:

- original amount;
- cleared amount derived from active settlements;
- remaining amount;
- currency;
- due date;
- partial settlements;
- overpayments/credits through explicit handling;
- reversals;
- aging reports;
- no direct `bank_transaction_id` on the invoice.

### 9.7 Attachments

Store:

- original filename;
- MIME type validated from content;
- size;
- cryptographic hash;
- storage disk/path identifier;
- source type;
- original structured payload where applicable;
- immutable version/reference metadata;
- uploader/actor;
- timestamps.

Private financial documents must never be placed on a public disk by default.

---

## 10. Exact monetary representation

### 10.1 Prohibited

Never use:

- PHP floats for monetary arithmetic;
- database `float` or `double` columns for money;
- implicit locale parsing;
- binary floating-point comparison with epsilon;
- formatting helpers as calculation logic.

### 10.2 Required

Use a mature Money value object and exact arithmetic.

Persist document totals, statement amounts, settlements, and journal debit/credit amounts as integer minor units with ISO currency and known currency scale where practical.

Persist quantities, exchange rates, unit prices, and intermediate calculation factors as sufficiently precise decimals represented as strings/value objects.

Define and test:

- currency exponents including 0- and 3-decimal currencies;
- rounding mode;
- invoice-level versus line-level rounding policy;
- tax rounding;
- exchange-rate precision;
- sign conventions;
- allocation remainder handling;
- exact equality used for reconciliation completion.

The package must reject currency/scale mismatches instead of silently coercing them.

---

## 11. Double-entry ledger

### 11.1 Ledger tables

Implement at least:

- `accounting_ledger_accounts`;
- `accounting_account_roles` or an equivalent semantic mapping;
- `accounting_tax_codes`;
- `accounting_tax_rule_versions`;
- `accounting_posting_rules`;
- `accounting_posting_rule_versions`;
- `accounting_journal_entries`;
- `accounting_journal_lines`;
- `accounting_periods`;
- `accounting_audit_events`.

### 11.2 Accounts

Support a configurable chart of accounts per Legal Entity.

Accounts require at least:

- UUID/code/name;
- account type/category;
- normal balance;
- currency constraints where relevant;
- semantic role(s), such as bank, receivable, payable, output tax, input tax, revenue, personnel expense, rounding, exchange gain/loss, and suspense;
- parent/group structure where useful;
- active/archive state;
- validity dates where needed;
- immutable historical meaning after being referenced.

Do not bake SKR03/SKR04 account numbers into domain code. German profiles may seed or map semantic roles to a selected chart. Respect licensing of third-party chart content.

### 11.3 Journal entry invariants

Every posted journal entry must:

- belong to one Legal Entity;
- have a UUID and immutable sequence/reference;
- have at least two lines;
- balance exactly in the Legal Entity base currency;
- preserve transaction currency, base currency, and exchange-rate snapshot where relevant;
- have a source type/reference;
- identify the posting period;
- record posting actor/time;
- reject posting into a closed period;
- be idempotent for the same source operation;
- become immutable after posting;
- be corrected only by an explicit linked reversal/correction entry.

The posting operation must use a database transaction and appropriate locks/idempotency constraints.

Do not trust only application validation. Add database-level unique constraints and check constraints where portable and effective. Document invariants that cannot be expressed portably at the database layer and test them heavily.

### 11.4 Debit and credit representation

Use explicit debit/credit semantics. Avoid an ambiguous single amount whose meaning changes based on account type.

At most one of debit or credit may be non-zero on a journal line. Zero-value posted lines are forbidden unless a verified statutory/export reason is documented.

### 11.5 Posted-record immutability

Normal update/delete operations on posted journals and lines must fail.

Protect against:

- Filament edit/delete actions;
- mass assignment;
- model save/delete calls;
- cascades from referenced operational data;
- closed-period changes;
- accidental reassignment to another entity.

Soft delete alone is not an adequate accounting correction mechanism.

### 11.6 Periods

Support:

- open periods;
- soft close/review where useful;
- hard close;
- explicitly authorized reopen with reason and immutable audit event;
- prevention of backdated posting into a hard-closed period;
- fiscal year boundaries independent of calendar year.

---

## 12. Posting rules and the WISO-style “Steuerfall” UX

### 12.1 Domain model

`PostingRule` is a stable user-facing identity. `PostingRuleVersion` contains the effective accounting recipe.

Support:

- stable code;
- translated label and explanation;
- jurisdiction/compliance profile;
- valid-from/valid-to;
- permitted transaction direction;
- required/optional receipt behavior;
- required tax choices;
- semantic account mappings;
- tax code/rule mapping;
- generated journal-line templates;
- customization per Legal Entity;
- archived versions that remain resolvable for historical postings.

Examples of German labels may include:

- Sonstige Betriebsausgaben;
- Personalkosten;
- Versicherungen;
- Bankgebühren;
- Privateinlage/Privatentnahme where applicable;
- Umbuchung;
- ungeklärter Posten.

These labels are UX. The persisted truth is the resolved balanced journal entry plus rule/version snapshot.

### 12.2 No destructive rule changes

Editing a posting rule creates a new version. Previously posted journal entries retain their original rule/version and resolved accounts/tax data.

### 12.3 Rule preview

Before final reconciliation, show a human-readable posting preview:

- debit account(s);
- credit account(s);
- tax basis and amount;
- currency;
- linked receipt/document;
- resulting open-item settlement;
- any remainder or imbalance.

Normal users can see friendly labels. Authorized accounting users can inspect full journal details.

---

## 13. Canonical bank-feed abstraction

### 13.1 Generic contracts

Define explicit generic contracts such as:

- `BankFeedDriver`;
- `BankAccountData` DTO;
- `BankStatementLineData` DTO;
- `BankFeedImportResult`;
- `BankSourceLinkGenerator` where useful;
- a driver registry configured explicitly per panel/application.

The generic package must know only driver keys and DTOs, not provider model classes.

### 13.2 Accounting bank accounts

Create canonical accounting bank accounts linked to ledger bank accounts.

Support:

- UUID;
- Legal Entity;
- display name;
- IBAN/BIC or provider-neutral identifiers;
- currency;
- mapped ledger account;
- driver key;
- external account reference;
- active/import state;
- unique driver/external-reference constraints per entity.

### 13.3 Bank statement lines

`BankStatementLine` is the canonical Accounting copy of an external transaction.

Store at least:

- UUID;
- Legal Entity and canonical bank account;
- driver key;
- stable external ID;
- source account external ID;
- signed amount in minor units;
- currency;
- booking date and value date;
- source status (`pending`, `booked`, `storno` or normalized equivalent);
- counterparty name and available account identifiers;
- purpose/remittance information;
- end-to-end/reference identifiers;
- source payload snapshot containing only necessary normalized source data;
- source hash;
- source created/updated timestamp where available;
- first/last import timestamps;
- reconciliation status derived from active reconciliation data;
- source link metadata without coupling core code to a provider Resource.

Unique constraint:

```text
legal_entity_id + driver_key + external_id
```

Import must be idempotent.

### 13.4 Source-data lifecycle

Pending lines may be updated from the external source before posting.

Once a statement line has produced a posted reconciliation:

- material changes to amount, currency, direction, or identity must not silently rewrite posted accounting truth;
- flag an exception/review condition;
- create an explicit adjustment/reversal workflow where appropriate;
- retain prior source snapshots/hashes needed to explain the change.

The Accounting copy must survive deletion or deactivation of the source connector.

---

## 14. FinTS bridge package

### 14.1 Responsibilities

The bridge must:

- implement the generic Accounting bank-feed driver using `FilamentFints` public APIs/models;
- map a FinTS bank account UUID to an Accounting bank account;
- map a FinTS transaction UUID to an Accounting statement line external ID;
- listen to the new durable FinTS integration event;
- activate tenant context before querying either package;
- rescan/upsert the affected account idempotently;
- not rely on `imported > 0`;
- preserve pending-to-booked identity;
- expose safe source URLs only through an explicit link generator;
- add bridge-specific commands for manual resync/recovery;
- include complete integration tests with fakes and Testbench;
- contain no tax rules, invoice state changes, or ledger posting behavior.

### 14.2 Import behavior

The bridge listener may queue work. Queued work must contain only durable scalar references and no credentials.

Recommended workflow:

1. Receive durable FinTS event.
2. Activate owner/tenant context.
3. Resolve and authorize the mapped Accounting Legal Entity.
4. Resolve/upsert the Accounting bank account.
5. Query FinTS transactions changed within the safe sync window or cursor.
6. Map each row to `BankStatementLineData`.
7. Call the generic Accounting import service.
8. Record an import run/audit result.
9. Retry only idempotent import work.
10. Never retry a banking payment submission as part of this process.

### 14.3 Mapping configuration

Provide an explicit resolver/mapping strategy for FinTS owner → Accounting Legal Entity.

The demo uses the same `LegalEntity` model as FinTS owner. Other hosts may bind a mapping service.

Fail closed on missing or ambiguous mapping. Never import into a global/default entity as a fallback.

### 14.4 Install and plugin configuration

Provide an explicit, documented setup resembling:

```php
FilamentFintsPlugin::make()
    ->transactions(false);

FilamentAccountingPlugin::make()
    ->bankFeeds([
        // Explicit bridge driver registration.
    ]);
```

Use the actual final API implemented by the packages. Do not leave this as pseudocode in delivered documentation.

---

## 15. Bank reconciliation model

### 15.1 Reconciliation aggregate

One statement line has at most one active finalized reconciliation, but may have draft/reversed history.

Support:

- draft;
- ready/validated;
- posted/finalized;
- reversed;
- review/exception state.

Record:

- statement line;
- Legal Entity;
- resulting payment/direct-posting journal entry;
- actor and timestamps;
- source rule/match information;
- reversal link;
- concurrency/version token;
- immutable audit history.

### 15.2 Reconciliation splits

A reconciliation contains one or more signed splits whose exact sum must equal the signed bank statement amount before finalization.

Each split must have exactly one explicit accounting purpose, such as:

- settle an `OpenItem`;
- direct posting through a `PostingRuleVersion`;
- direct posting to an explicitly authorized ledger account;
- bank fee;
- internal transfer/clearing;
- suspense/clarification with required reason.

Prefer explicit foreign keys and database integrity over a broad unvalidated polymorphic target. If a polymorphic design is selected, justify it in an ADR and add strict type allowlists and integrity tests.

### 15.3 Split examples

Example: outgoing bank line `-1,210.00 EUR`:

```text
-1,190.00 EUR -> settle supplier bill open item
   -20.00 EUR -> bank-fee posting rule
----------------
-1,210.00 EUR -> exact statement amount
```

Example: incoming bank line `+2,000.00 EUR`:

```text
+1,000.00 EUR -> sales invoice A
  +750.00 EUR -> sales invoice B
  +250.00 EUR -> customer prepayment/credit
----------------
+2,000.00 EUR -> exact statement amount
```

### 15.4 Partial and many-to-many settlement

The model must support:

- one payment settling several invoices;
- several payments settling one invoice;
- one statement line partially assigned while draft;
- exact partial settlement of an open item;
- credit notes and customer/supplier credits;
- overpayment handling;
- split fees/discounts/rounding;
- no direct one-to-one `bank_transaction_id` on documents.

### 15.5 Finalization

Finalization must occur in a database transaction with locks on the statement line, reconciliation, relevant open items, and idempotency key.

Finalization must:

1. verify entity isolation;
2. verify statement line is booked unless an explicitly documented exception applies;
3. verify exact split sum;
4. verify open-item remaining balances;
5. resolve posting-rule versions and tax behavior;
6. create one balanced journal entry;
7. create settlement records;
8. mark the reconciliation posted;
9. emit a domain event after commit;
10. remain idempotent under duplicate clicks or retries.

### 15.6 Reversal

Never delete or edit the posted reconciliation/journal.

Create an explicit reversing journal entry, reverse settlements, retain the original record, and mark the reconciliation reversed with actor, timestamp, reason, and reference.

---

## 16. Matching and suggestions

Suggestions are assistive, not accounting truth.

Implement deterministic, explainable matching signals such as:

- exact end-to-end ID;
- invoice/document number in purpose;
- exact or permissible amount relationship;
- party IBAN/account identifier;
- normalized party name;
- due-date/booking-date proximity;
- customer/supplier account;
- known payment reference;
- direction and currency;
- already-open amount.

Each suggestion must expose reasons and a score/confidence representation.

Rules:

- do not auto-post by default;
- do not match across Legal Entities;
- do not suggest already fully cleared open items;
- do not guess when candidates are ambiguous;
- do not use opaque AI/ML in v1;
- design the matcher behind a contract so future strategies can be added;
- test ambiguity, duplicate invoice numbers, same amounts, missing references, and adversarial tenant data.

---

## 17. Invoice and payment accounting flows

### 17.1 Sales invoice

When a sales invoice is issued and posted:

- debit receivables;
- credit revenue accounts;
- credit output tax where applicable;
- create a receivable open item;
- snapshot all resolved accounting/tax data;
- lock the issued commercial content against normal editing.

### 17.2 Customer payment

When an incoming bank statement line settles a sales invoice:

- debit bank;
- credit receivables;
- create settlement(s) against the open item;
- update payment status by derivation, not direct truth flags.

### 17.3 Purchase invoice

When a purchase invoice is accepted and posted:

- debit expense/asset accounts;
- debit recoverable input tax where applicable;
- credit payables;
- create a payable open item;
- preserve original incoming document and structured payload.

### 17.4 Supplier payment

When an outgoing bank statement line settles a purchase invoice:

- debit payables;
- credit bank;
- create settlement(s);
- handle bank fee/discount differences as explicit splits and postings.

### 17.5 Direct bank expense/income

If no invoice/open item exists, the user may select a Posting Rule. The rule expands into balanced lines, including tax where applicable.

Do not infer that every bank payment creates or changes VAT at payment time. Tax timing depends on configured jurisdiction, accounting basis, and taxation method. Put this behavior behind compliance/tax policy services and test the selected profile.

---

## 18. German compliance profile

Germany is the first supported compliance profile, not a hard-coded global assumption.

### 18.1 Primary legal sources

Verify implementation assumptions against current primary sources, including:

- § 146 AO: https://www.gesetze-im-internet.de/ao_1977/__146.html
- current GoBD materials from the Bundesministerium der Finanzen;
- BMF E-Rechnung FAQ: https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
- current UStG/UStDV invoice requirements;
- applicable EU EN 16931 and German XRechnung/ZUGFeRD specifications from authoritative sources.

Do not rely solely on blogs, vendor marketing, remembered law, or stale summaries.

### 18.2 Core features required for German use

Support or lay a complete tested foundation for:

- complete and ordered individual records;
- immutable posted entries;
- original content remaining discoverable after changes;
- automatic audit logging;
- versioned master/configuration data;
- period locks/festschreibung;
- correction through reversal and linked replacement;
- machine-readable export architecture;
- documented data access/export capability;
- retention metadata and non-destructive archival;
- procedural documentation describing the package behavior and host responsibilities.

### 18.3 Tax profile

German tax behavior must be versioned by effective dates.

Support the architecture needed for:

- standard and reduced VAT rates;
- zero/exempt cases with explicit reason/category;
- input/output tax distinction;
- reverse charge;
- intra-community cases;
- small-business treatment where applicable;
- cash versus accrual VAT timing where configured;
- recoverability restrictions;
- historical tax rates and rule changes;
- tax-code export mappings.

Do not attempt to encode every German tax scenario as a hard-coded enum in v1. Use versioned rules and validated extension points.

### 18.4 SKR/account charts

Provide generic account-chart import/seed infrastructure and semantic role mapping.

Do not ship third-party-proprietary account-chart content without verifying the right to do so.

Keep internal posting logic independent of specific SKR03/SKR04 numbers.

### 18.5 DATEV/export architecture

Define an exporter contract and implement a documented German export path only if the exact current specification and licensing permit it.

At minimum, the internal model must preserve all information an exporter needs:

- dates;
- debit/credit accounts;
- amount/currency;
- tax code/version;
- document fields;
- references;
- text;
- entity/fiscal period;
- immutable source links.

Do not call a generic CSV “DATEV compatible” without verifying the required format.

---

## 19. E-invoice architecture

### 19.1 General rules

Treat the structured e-invoice data as authoritative where current law/format requires it.

Store the original structured file unchanged, along with hash, MIME type, format/profile, validation result, and human-readable rendering metadata.

Do not store only a generated PDF and discard XML.

### 19.2 Formats

Create adapter contracts for:

- parsing;
- validation;
- normalization to the document DTO;
- generation;
- rendering/visualization;
- attachment extraction;
- correction references.

Evaluate mature, maintained libraries for XRechnung/ZUGFeRD/EN 16931. Verify PHP/Laravel compatibility, license, supported profiles, validation quality, and maintenance before selecting a dependency.

Do not implement XML standards manually if a suitable maintained library exists.

### 19.3 Incoming invoices

Support:

- upload/import of structured e-invoice;
- validation with actionable errors/warnings;
- normalized preview;
- supplier matching or creation;
- duplicate detection;
- immutable original storage;
- conversion to purchase invoice draft;
- posting only after explicit review/authorization.

### 19.4 Outgoing invoices

Support the architecture needed to:

- generate structured output from the issued invoice snapshot;
- validate before release;
- retain the exact released structured payload;
- generate a human-readable representation;
- issue a linked correction document rather than silently changing released content.

If full format generation is outside a verified safe v1 dependency, do not fake support. Complete the adapter, validation, storage, and test foundation and clearly state the remaining external format implementation as a scoped blocker. However, every capability claimed in README/UI must work.

---

## 20. International design

The generic Accounting package must not use code such as:

```php
if ($country === 'DE') {
    // all accounting logic
}
```

Use explicit compliance/tax policy contracts and registered profiles.

Legal Entity configuration must drive:

- jurisdiction;
- base currency;
- fiscal calendar;
- account chart;
- tax registrations;
- invoice numbering;
- rounding policy;
- document requirements;
- reporting profile;
- accounting/tax timing policies.

Support multiple tax registrations structurally even if the first UI focuses on one German registration.

German and English are UI languages, not tax jurisdictions.

---

## 21. Filament v5 plugin

### 21.1 Plugin registration

Implement `FilamentAccountingPlugin` using official Filament v5 panel-plugin conventions.

Provide explicit per-panel configuration for features/resources. Do not depend on global component configuration.

Example feature categories:

- dashboard;
- customers;
- suppliers;
- catalog;
- sales invoices;
- purchase invoices;
- bank reconciliation;
- journal;
- chart of accounts;
- tax/posting rules;
- reports;
- settings;
- audit.

### 21.2 Navigation

Use a coherent Accounting navigation group and sensible ordering.

Suggested user-facing resources/pages:

- Accounting overview;
- Sales invoices;
- Purchase invoices;
- Customers;
- Suppliers;
- Products & services;
- Bank transactions;
- Journal;
- Chart of accounts;
- Posting rules;
- Tax settings;
- Reports;
- Audit log;
- Settings.

Use role/ability checks for navigation visibility and actions.

### 21.3 Unified bank transaction Resource

In an accounting-enabled panel, this Resource replaces the raw FinTS transaction Resource.

Table requirements:

- account selector;
- booking/value date;
- counterparty;
- purpose/reference;
- signed amount with exact formatted Money;
- pending/booked/storno badge;
- reconciliation badge (`unassigned`, `partial`, `assigned`, `review`);
- linked targets summary;
- source driver/provider where useful;
- search/filter/sort at database level;
- filters for account, date, direction, status, reconciliation, amount range, party, and source;
- default focus on unassigned booked lines;
- no N+1 relationship queries;
- pagination and indexes suitable for large transaction volumes.

Actions:

- reconcile;
- split;
- review details;
- open linked invoice/journal/open item;
- reverse reconciliation when authorized;
- mark/route to clarification through a real posting rule, not a hidden ignore flag.

### 21.4 Reconciliation experience

Provide an intuitive reconciliation page or large modal with:

- bank statement line summary;
- source details;
- suggested matches and explanations;
- open invoice/bill search;
- Posting Rule selection;
- split editor;
- live exact remaining amount;
- currency validation;
- journal preview;
- attachment/receipt access;
- warnings for pending/storno/closed period;
- explicit final confirmation;
- duplicate-click protection;
- responsive desktop/tablet/mobile layout.

Do not hide an imbalance through automatic rounding. Any rounding split must be visible and use a configured rounding account/rule.

### 21.5 Invoice Resources

Show separate Resources/navigation for outgoing and incoming invoices while sharing domain logic.

Each view must show:

- commercial document status;
- posting status;
- payment/open amount status;
- line/tax totals;
- original/generated attachments;
- journal entry link;
- open item;
- all related settlements and bank statement lines;
- clickable navigation in both directions;
- corrections/reversals;
- immutable issued/posted state.

### 21.6 Journal and audit UI

Posted journal entries are view-only.

Display:

- debit/credit lines;
- amounts and currencies;
- source document/reconciliation;
- rule/tax snapshots;
- actor/time;
- reversal chain;
- period;
- audit events.

No ordinary delete/edit actions for posted records.

### 21.7 Localization

Ship complete German and English translations using package translation namespaces.

No hard-coded German/English strings in Resources, actions, notifications, validation messages, enums, statuses, or navigation.

Tests must run in both locales for critical pages and workflows.

---

## 22. Authorization

Provide explicit abilities, configurable through `AccountingAuthorizer` and Laravel Gate/policies.

At minimum distinguish:

- view accounting;
- manage parties/catalog;
- create/edit draft invoices;
- issue invoices;
- register purchase invoices;
- post documents;
- view bank transactions;
- draft reconciliation;
- finalize reconciliation;
- reverse reconciliation;
- view journal;
- create manual journal drafts;
- post manual journals;
- manage chart/tax/posting rules;
- close periods;
- reopen periods;
- view audit;
- manage Accounting settings.

Authorization must be checked in:

- Filament navigation;
- Resource queries;
- actions;
- service entry points;
- routes/downloads;
- queued operations where relevant.

UI visibility is not an authorization boundary.

---

## 23. Security and privacy

### 23.1 Financial data

- private storage by default;
- authorized download routes with entity scoping;
- safe MIME handling;
- no path traversal;
- no executable public uploads;
- encrypt especially sensitive configuration where justified;
- redact logs;
- never log full bank credentials, PINs, TANs, auth state, full invoices, or raw sensitive payloads unnecessarily.

### 23.2 Audit

Audit events must include:

- Legal Entity;
- actor reference;
- operation;
- target reference;
- timestamp;
- reason where required;
- safe before/after or change metadata;
- correlation/idempotency key;
- source request/job identifier where useful.

Do not store secrets in audit payloads.

### 23.3 Concurrency and replay

Protect against:

- duplicate invoice issue clicks;
- duplicate document posting;
- duplicate reconciliation finalization;
- simultaneous settlement of the same open amount;
- repeated bridge events;
- queued retry;
- stale UI data;
- ambiguous external source updates.

Use unique idempotency keys, optimistic version checks, row locks, and after-commit events as appropriate.

### 23.4 Webhooks and external APIs

No external service is required for the initial demo. If an integration is added, validate signatures, enforce replay protection, and keep it behind a provider contract.

---

## 24. Existing ledger-engine evaluation

Do not reinvent accounting concepts, but do not adopt an external engine blindly.

### 24.1 Mandatory spike

Evaluate the current stable version/master of:

```text
https://github.com/ekmungai/eloquent-ifrs
```

It currently appears relevant because it provides double entry, multiple entities, VAT, currencies, invoices/bills, assignments/partial clearing, periods, and reports, and declares Laravel 13 compatibility.

Verify all of that against real current code and tests.

### 24.2 Known concerns to validate

Explicitly inspect:

- its `Auth::user()->entity` coupling/global Entity scope;
- its modification of the host users table;
- background-job behavior when no authenticated user exists;
- multi-tenant isolation and multi-database support;
- ability to use host-resolved Legal Entity context;
- hard-coded transaction/clearable types;
- posted-entry mutation protections;
- monetary precision and use of float type hints;
- tax rule expressiveness for Germany;
- compatibility with package-owned documents/open items;
- migration and table ownership;
- ability to remain an internal engine behind an adapter rather than leak into public APIs.

### 24.3 Decision rule

Define a `LedgerEngine` contract in `filament-accounting` regardless of the selected implementation.

Use Eloquent IFRS only if it can satisfy tenant isolation, host-model independence, exact-money, immutability, and package integration without unsafe global-scope bypasses or a permanent fork that cannot be maintained.

If it is suitable after adaptation:

- integrate it behind a dedicated adapter;
- keep public Accounting APIs engine-neutral;
- document mappings and limitations;
- add integration/upgrade tests.

If it is unsuitable:

- record the evidence in `docs/adr/`;
- implement the smallest complete first-party ledger engine needed by this specification using standard double-entry principles;
- do not copy third-party source without license compliance;
- do not leave the ledger as a future TODO.

Also briefly evaluate other currently maintained, appropriately licensed PHP ledger libraries. Reject or accept based on concrete evidence, not stars or marketing claims.

---

## 25. Migrations and database integrity

### 25.1 Migration safety

- additive migrations;
- no destructive reset assumptions;
- safe upgrades from prior package versions;
- deterministic backfills;
- transaction use where supported;
- chunk large backfills;
- never use application context that may be unavailable during migrations;
- documented rollback limitations for legally immutable data;
- migration tests from empty DB and representative upgraded DB.

### 25.2 Foreign keys and indexes

Use real foreign keys inside each package database where compatible with tenancy architecture.

Index at least:

- Legal Entity + status/date combinations used by Resources;
- document number uniqueness;
- supplier invoice duplicate checks;
- open item party/due/status;
- journal entry date/period/status;
- journal lines account/date access path;
- bank account source identity;
- statement line driver/external identity;
- statement line account/date/status;
- reconciliation state;
- settlement open-item relationships;
- audit entity/target/time;
- idempotency/correlation keys.

Use query plans or realistic tests for large tables. Avoid loading transaction sets into PHP for filtering/sorting/pagination.

### 25.3 Cascade policy

Do not cascade-delete posted accounting truth because an operational parent is removed.

Use restrict/archive semantics for referenced entities. The canonical Accounting statement line must survive FinTS connection/account deletion.

---

## 26. Commands and installation

### 26.1 Accounting package commands

Implement and document commands such as:

```text
php artisan filament-accounting:install
php artisan filament-accounting:install --migrate
php artisan filament-accounting:seed-profile DE
php artisan filament-accounting:verify
```

Names may be refined, but installation, configuration publication, migration, profile setup, and integrity verification must be ergonomic and tested.

### 26.2 Bridge commands

Implement and document:

```text
php artisan filament-accounting-fints:install
php artisan filament-accounting-fints:sync
php artisan filament-accounting-fints:sync --account=<uuid>
```

Manual resync must be idempotent and tenant-scoped.

### 26.3 Scheduler/queue

Document optional queues and scheduler tasks for:

- bridge imports;
- e-invoice processing if queued;
- integrity/retention checks;
- no unattended bank payment submission.

---

## 27. Testbench workbenches and demo environment

### 27.1 Committed package workbench

Each new package must include:

- `workbench/`;
- `testbench.yaml`;
- package tests;
- a minimal Filament panel provider;
- representative fake Legal Entity/User models;
- fake ownership/actor resolvers;
- synthetic data only.

The bridge workbench may consume local sibling packages during development without committing machine-specific absolute paths.

### 27.2 Herd setup script

Commit a script in the Accounting package:

```text
scripts/setup-herd-demo.ps1
```

It must:

- create or safely update `%USERPROFILE%\Herd\filament-accounting-demo`;
- install Laravel 13 and Filament v5;
- configure Composer path repositories using the exact paths above;
- require all packages;
- register `/admin` panel plugins;
- generate a local demo user without hard-coded production credentials;
- publish configs/migrate/seed synthetic data;
- document non-destructive rerun behavior;
- never copy package source into the demo;
- never commit the demo into a package repo;
- never configure real FinTS credentials.

### 27.3 Demo fixtures

Create realistic synthetic scenarios:

- one German GmbH Legal Entity;
- optional second Legal Entity to demonstrate isolation;
- customers and suppliers;
- products/services;
- issued sales invoices;
- purchase invoices with attachments/fake structured files;
- unpaid, partially paid, paid, and overpaid open items;
- booked and pending bank statement lines;
- exact invoice match;
- one payment covering several invoices;
- partial payment;
- supplier payment plus bank fee split;
- direct operating expense;
- personnel-cost posting rule without pretending to implement payroll;
- storno/reversal scenario;
- closed period rejection;
- German and English UI switching.

The demo must be usable without a live bank.

---

## 28. Testing requirements

### 28.1 Baseline quality

Configure and pass:

- Composer validation;
- full unit/feature/integration test suite;
- PHPStan/Larastan at a strict practical level;
- Laravel Pint;
- Filament/Livewire component tests;
- package installation tests;
- localization completeness tests;
- architecture/dependency tests;
- security and authorization tests.

### 28.2 Ledger invariants

Test at least:

- debits equal credits exactly;
- mismatched entries cannot post;
- zero/one-line entries cannot post;
- cross-currency requirements;
- closed-period rejection;
- posted-entry mutation rejection;
- reversal preserves original and balances correctly;
- duplicate source posting is idempotent;
- concurrent post attempts result in one posting;
- entity isolation;
- exact rounding with 0-, 2-, and 3-decimal currencies;
- no float drift.

Use property-based/generative tests where they materially improve monetary and balance confidence.

### 28.3 Document tests

Test:

- draft editability;
- issue number uniqueness under concurrency;
- issued snapshot immutability;
- line/tax/rounding totals;
- credit note/correction linking;
- duplicate supplier invoice detection;
- attachment privacy and MIME validation;
- document/journal/open-item creation atomicity;
- payment status derivation.

### 28.4 Reconciliation tests

Test:

- exact match;
- partial payment;
- many invoices per payment;
- many payments per invoice;
- split posting;
- bank fee difference;
- overpayment;
- amount mismatch rejection;
- currency mismatch rejection;
- pending line cannot finalize;
- pending-to-booked transition;
- storno/reversal;
- closed period;
- duplicate click/retry;
- concurrent settlement of same open item;
- cross-entity attack;
- ambiguous matching suggestions;
- source change after posted reconciliation.

### 28.5 Bridge tests

Test:

- FinTS UUID migration/backfill;
- UUID preservation pending → booked;
- durable event dispatch after persistence;
- bridge event serialization;
- no action based solely on imported count;
- idempotent rescan/upsert;
- repeated event;
- mapping failure closes safely;
- owner/actor/tenant isolation;
- queued listener context activation;
- source connector deletion does not delete Accounting copy;
- no FinTS credentials in jobs/events/logs;
- standalone Accounting works without FinTS;
- standalone FinTS works without Accounting;
- bridge package fails installation clearly if dependency versions are incompatible.

### 28.6 Filament tests

Test:

- Resources are registered only when enabled;
- raw FinTS transaction Resource can be disabled;
- Accounting bank Resource appears instead;
- table scoping/filtering/search/sort/pagination;
- action authorization;
- reconciliation split form validation;
- exact live remainder;
- navigation links in both directions;
- posted records have no edit/delete actions;
- German and English labels/statuses/notifications;
- responsive-critical schemas do not rely on desktop-only behavior.

### 28.7 Database matrix

Use SQLite where useful for fast package tests, but include CI/integration coverage against the primary supported production database behavior, especially MariaDB/MySQL constraints, locking, decimals, indexes, and migrations.

Do not assume SQLite behavior proves production locking or constraint behavior.

---

## 29. CI/CD

Create GitHub Actions workflows for each new package and update FinTS CI only when required.

CI must include:

- supported PHP versions;
- Laravel 13 / Filament v5 dependency resolution;
- Composer validation;
- tests;
- static analysis;
- formatting check;
- appropriate database service job for integration behavior;
- no real bank access;
- no external secrets required;
- deterministic synthetic fixtures;
- cache keys that cannot mask dependency changes.

The bridge CI must test against compatible branches/versions of both packages.

Do not use `dev-master` in a way that makes CI non-reproducible without documenting the temporary development constraint. Prepare clean version constraints and release ordering.

---

## 30. Performance and scale

Design and test for at least:

- tens of thousands of documents;
- hundreds of thousands of journal lines;
- hundreds of thousands of bank statement lines per installation/entity;
- database-side search/filter/sort/pagination;
- batched/idempotent bank import;
- no per-row N+1 status calculation;
- derived reconciliation/open amount values computed efficiently;
- indexes aligned with actual Resource queries;
- queue-safe imports;
- bounded memory usage.

Do not denormalize accounting truth merely for UI convenience. Use safe projections/counters only when transactionally maintained and independently verifiable.

---

## 31. Documentation deliverables

### 31.1 Accounting repository

Create and maintain at least:

- `README.md`
- `CHANGELOG.md`
- `LICENSE`
- `docs/GROK_BUILD_PROMPT.md` containing this complete canonical prompt
- `docs/architecture.md`
- `docs/domain-model.md`
- `docs/ledger-invariants.md`
- `docs/bank-reconciliation.md`
- `docs/ownership-tenancy.md`
- `docs/germany-compliance.md`
- `docs/e-invoicing.md`
- `docs/security.md`
- `docs/LOCAL_DEVELOPMENT.md`
- `docs/operations-and-retention.md`
- `docs/adr/` for consequential decisions, including ledger engine selection.

Do not scatter or replace this canonical build specification with multiple incomplete prompt files. Supporting implementation documentation is required, but the complete build instruction remains consolidated in `docs/GROK_BUILD_PROMPT.md`.

### 31.2 Bridge repository

Create at least:

- `README.md`
- `CHANGELOG.md`
- `LICENSE`
- `docs/architecture.md`
- `docs/installation.md`
- `docs/ownership-tenancy.md`
- `docs/sync-and-recovery.md`
- `docs/security.md`
- `docs/LOCAL_DEVELOPMENT.md`

### 31.3 FinTS documentation update

Document:

- transaction UUID;
- new durable integration event;
- backward compatibility;
- how external packages consume bank statement changes;
- why FinTS does not contain accounting assignments;
- how to disable the raw transaction Resource in an Accounting panel.

### 31.4 Procedure and compliance documentation

Explain:

- which technical controls the package provides;
- which host/deployment controls remain the operator’s responsibility;
- mutation/reversal rules;
- audit behavior;
- data retention and export;
- backups and restore expectations;
- version/change management;
- why no blanket certification claim is made.

---

## 32. Explicit non-goals for v1

Do not expand v1 into an uncontrolled ERP project.

Out of scope unless needed for a listed invariant:

- payroll calculation;
- inventory/warehouse management;
- manufacturing;
- fixed-asset depreciation engine;
- ELSTER submission;
- complete statutory tax return preparation;
- AI/ML auto-posting;
- credit scoring;
- debt collection automation;
- payment initiation duplicated from FinTS;
- PSD2/Open-Banking provider implementations beyond the generic driver contract;
- full consolidation of international groups;
- certification claims.

However:

- personnel cost may exist as a posting rule;
- asset purchases may post to an asset account;
- tax/export interfaces must be extensible;
- the domain must not block later modules.

Do not leave an in-scope feature incomplete by relabeling it as a non-goal.

---

## 33. Prohibited shortcuts and anti-patterns

Do not:

- make FinTS depend on Accounting;
- make Accounting depend on FinTS;
- store `tax_case_id` on a FinTS transaction;
- store `bank_transaction_id` directly on an invoice as the source of truth;
- use a static enum as the complete tax model;
- dynamically inject cross-package Eloquent relations;
- use `class_exists()` for hidden optional integration;
- mutate issued documents or posted journals;
- use soft delete as reversal;
- use floats for money;
- use public storage for private invoices;
- derive current Legal Entity from untrusted request data;
- use a global unscoped fallback entity;
- load complete bank transaction sets into PHP for table operations;
- treat a FinTS `imported` count of zero as no changes;
- post pending bank transactions by default;
- auto-post ambiguous suggestions;
- silently absorb allocation differences;
- delete accounting history when a connector is deleted;
- log sensitive FinTS/accounting payloads;
- hit a real bank or submit a real transfer in CI;
- put real credentials in demo fixtures;
- modify upstream `Fhp\` code for Accounting concerns;
- copy dependencies’ code without license compliance;
- expose a third-party ledger model directly as the public Accounting API without an adapter decision;
- leave critical TODOs, placeholders, or pseudocode in production delivery.

---

## 34. Required implementation sequence

Follow this order unless real repository evidence requires a documented adjustment.

### Phase 1 — Discovery and baselines

1. Inspect all repositories and working trees.
2. Record branches, versions, existing changes, tests, static analysis, and CI.
3. Run FinTS baseline tests.
4. Verify current Laravel 13, Filament v5, PHP, Testbench, and dependency compatibility.
5. Verify current primary legal/standard sources.
6. Write the concrete plan.

### Phase 2 — Architecture and engine decision

1. Create package scaffolds using established Laravel package conventions.
2. Define bounded-context contracts and DTOs.
3. Complete the ledger-engine spike.
4. Record the ADR and select the implementation.
5. Finalize schema/invariants before building UI.

### Phase 3 — Ledger and document core

1. Legal Entity/context/authorization.
2. exact Money types.
3. chart/accounts/tax/posting rules.
4. journal/lines/periods/audit.
5. parties/catalog.
6. documents/lines/snapshots.
7. open items/settlements.
8. service-layer tests.

### Phase 4 — Generic banking and reconciliation

1. bank-feed contracts/DTOs/registry.
2. canonical bank accounts/statement lines.
3. matcher suggestions.
4. reconciliation/splits.
5. finalization/reversal.
6. performance/index tests.

### Phase 5 — FinTS compatibility and bridge

1. add UUID and durable event to FinTS.
2. preserve all FinTS tests and backward compatibility.
3. implement bridge mapping/import/listener/commands.
4. prove standalone and integrated behavior.

### Phase 6 — Filament UI

1. plugin registration/config.
2. party/catalog/document Resources.
3. unified bank transaction Resource.
4. reconciliation workflow.
5. journal/accounts/rules/audit/report views.
6. DE/EN localization.
7. authorization and responsive tests.

### Phase 7 — German profile and e-invoicing

1. versioned German rules/profile foundation.
2. invoice requirements/validation.
3. structured e-invoice adapters/storage/validation/generation as safely supported.
4. export architecture.
5. compliance and operator documentation.

### Phase 8 — Workbench, Herd demo, CI, and final QA

1. complete workbenches.
2. create reproducible Herd setup script.
3. populate synthetic demo.
4. run full CI-equivalent suites.
5. inspect UI manually in both locales.
6. run migration upgrade tests.
7. perform security, tenant-isolation, precision, and accounting-invariant reviews.
8. fix every critical/high issue found.
9. update docs and changelogs.
10. make atomic commits and report final commit/branch status.

---

## 35. Definition of Done

The project is complete only when all of the following are true.

### Package boundaries

- [ ] Accounting installs and tests without FinTS.
- [ ] FinTS installs and tests without Accounting.
- [ ] The bridge explicitly requires and integrates both.
- [ ] No hidden optional dependency detection exists.
- [ ] No Accounting fields exist in FinTS tables.
- [ ] No FinTS classes exist in generic Accounting code.

### FinTS

- [ ] Every bank transaction has a stable UUID.
- [ ] Existing rows are safely backfilled.
- [ ] Pending → booked preserves UUID.
- [ ] The old event remains backward compatible.
- [ ] The new durable event is after-commit and queue/tenant safe.
- [ ] Raw transaction Resource remains usable and disableable.
- [ ] Full FinTS quality suite passes.

### Ledger

- [ ] Posted entries balance exactly.
- [ ] Money arithmetic has no floats.
- [ ] Posted records are immutable.
- [ ] Corrections use linked reversals.
- [ ] Period locks work.
- [ ] Idempotency and concurrency are tested.
- [ ] Engine choice is documented and encapsulated.

### Documents and open items

- [ ] Parties support customer/supplier roles.
- [ ] Sales and purchase invoices share coherent domain logic.
- [ ] Issued documents store immutable snapshots.
- [ ] Posting creates journal entries and open items atomically.
- [ ] Partial/multiple payments are supported.
- [ ] Payment status is derived from settlements.
- [ ] Original attachments are private and hashed.

### Bank reconciliation

- [ ] Canonical statement lines are provider-neutral.
- [ ] Bridge import is idempotent.
- [ ] Accounting copy survives source deletion.
- [ ] Unassigned/partial/assigned/review statuses are correct.
- [ ] Exact, partial, many-to-many, fee, and split cases work.
- [ ] Finalization creates one balanced journal entry.
- [ ] Duplicate clicks/retries cannot double-post.
- [ ] Reversal preserves complete history.
- [ ] Suggestions are explainable and not silently posted.

### Tenancy and authorization

- [ ] Legal Entity is the accounting boundary.
- [ ] Owner, Actor, and Membership remain distinct.
- [ ] Cross-entity access is rejected at query and service levels.
- [ ] Background jobs activate tenant context before queries.
- [ ] Every sensitive action has explicit ability checks.

### Filament and localization

- [ ] All required Resources/pages/actions exist.
- [ ] Accounting bank Resource replaces raw FinTS Resource in demo.
- [ ] Navigation works in both directions.
- [ ] Posted documents/journals are view-only.
- [ ] German and English translations are complete.
- [ ] Critical workflows are responsive and tested.

### Germany and international readiness

- [ ] Germany is implemented as an explicit profile/policy set.
- [ ] Core logic is not hard-coded to Germany.
- [ ] Tax/posting rules are effective-dated/versioned.
- [ ] Audit, locks, reversals, exports, and retention are documented.
- [ ] E-invoice originals and structured data are preserved.
- [ ] Claims match actual implemented capabilities.

### Delivery

- [ ] Workbench/Testbench setup is committed in both new packages.
- [ ] Herd demo exists at the exact untracked path.
- [ ] Path repositories use symlinks.
- [ ] Setup script is safe and reproducible.
- [ ] All tests/static analysis/format checks pass.
- [ ] CI workflows pass without real credentials.
- [ ] Docs are complete.
- [ ] No critical TODOs/placeholders remain.
- [ ] Final self-review found no unresolved critical/high issue.

---

## 36. Final response format after implementation

When all work is complete, return a concise but evidence-based report containing:

1. What was built.
2. Repositories, branches, and commit SHAs.
3. Important architecture decisions, especially ledger-engine selection.
4. FinTS changes and backward-compatibility result.
5. Accounting and bridge package installation commands.
6. Exact Herd demo path and access URL/path.
7. Test/static-analysis/format results with counts where available.
8. Migration and upgrade verification.
9. Security, tenant-isolation, monetary-precision, and accounting-invariant review results.
10. Any non-critical known limitations that are truthfully outside v1 scope.
11. Confirmation that no real credentials or demo source were committed.

Do not claim success for commands, tests, migrations, CI, or integrations that were not actually executed and verified.

---

## 37. Final instruction

Begin by inspecting the real repositories and current primary documentation. Then create the plan and execute it completely.

The central architectural rule is:

> **FinTS owns bank connectivity and raw banking data. Accounting owns accounting documents, journal truth, open items, canonical bank statement lines, and reconciliation. A small explicit bridge maps between them.**

Preserve that boundary throughout schema design, service code, Filament UI, jobs, events, tests, and documentation.
