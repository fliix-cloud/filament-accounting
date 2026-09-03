# Upgrade to the unified Filament Accounting package

This guide applies to applications that previously installed any combination of
`fliix-cloud/filament-accounting`, `fliix-cloud/filament-fints`, and
`fliix-cloud/filament-accounting-fints`.

## Before deployment

1. Put bank synchronization and payment submission into a maintenance window.
2. Stop queue workers that can execute old FinTS or bridge jobs.
3. Create and verify backups of the database, attachment storage, audit-anchor
   storage, application configuration, and encryption keys.
4. Record the installed package versions and the output of the existing
   accounting verification commands.
5. Deploy `fliix-cloud/filament-accounting` with its transitive
   `fliix-cloud/php-fints` dependency. Keep the old package source available for
   rollback, but do not run old and new bank writers together.

## Model and namespace mapping

| Previous concept | Unified concept | Physical storage |
| --- | --- | --- |
| `FilamentFints\Models\BankConnection` | `FilamentAccounting\Banking\FinTs\Models\BankConnection` | Existing `fints_bank_connections`, extended with `legal_entity_id` |
| `FilamentFints\Models\BankAccount` plus `AccountingBankAccount` copy | `FilamentAccounting\Models\AccountingBankAccount` | `accounting_bank_accounts`; legacy FinTS ID retained for deterministic mapping |
| `FilamentFints\Models\BankTransaction` plus `BankStatementLine` copy | `FilamentAccounting\Models\BankStatementLine` (“Umsatz”) | `accounting_bank_statement_lines` plus append-only `accounting_bank_transaction_source_versions` |
| FinTS owner morph plus bridge `LegalEntityOwnerMapper` | Direct `legal_entity_id` | Existing FinTS tables gain verified Legal Entity references |
| Bridge driver/import jobs | `TransactionSyncService` → `UnifiedBankTransactionImporter` | No bridge table or runtime registry |
| Bridge source link | Direct canonical resource relationship | No source-link registry |
| Party mandate fields plus mirrored FinTS mandate | Authoritative `DirectDebitMandate` linked to `PartyBankAccount` | Existing `fints_direct_debit_mandates`, extended with Legal Entity/party links |
| `FilamentFints\*` Laravel layer | `FilamentAccounting\Banking\FinTs\*` | One package/provider/plugin/config/translation namespace |

Historical table names are intentionally retained where renaming would increase
upgrade risk. They do not indicate a second product package.

## Dry run

Run package migrations, then generate and retain the machine-readable report:

```bash
php artisan migrate --force
php artisan filament-accounting:consolidate-legacy --dry-run --json
```

The report lists discovered legacy tables and counts, explicit ownership
mappings, bank connections/accounts/transactions/payments/mandates, existing
reconciliation and settlement target counts, the legacy transaction evidence
hash, expected target counts, and blockers.

The command fails when an owner morph does not resolve uniquely to an existing
`LegalEntity`, or when a mandate cannot be linked uniquely to its existing
`PartyBankAccount`. Never resolve those cases from names or IBAN similarity.
Correct the source mapping explicitly and repeat the dry run until `blockers` is
empty. Repeating a dry run performs no writes.

## Apply and verify

```bash
php artisan filament-accounting:consolidate-legacy --json
php artisan filament-accounting:verify --json
```

The consolidation runs in one database transaction, uses stable source UUIDs
and IDs, is safe to repeat, converts decimal source values through exact minor
units, imports source evidence append-only, preserves existing reconciliation and
settlement records, writes an evidence hash and audit event, and does not drop
legacy tables. Retain both JSON reports with the release evidence.

After successful verification:

1. Register only `FilamentAccounting\FilamentAccountingPlugin::make()`.
2. Remove the old `FilamentFintsPlugin`, bridge provider/plugin, driver-key,
   owner-mapper, source-link, and bridge queue registrations from the host.
3. Publish only `config/filament-accounting.php`; migrate relevant FinTS
   environment variables to its `banking.fints` section.
4. Configure `FINTS_PRODUCT_ID`, restart the new queue workers, synchronize
   institutes, and test one connection through the UI.
5. Compare canonical account/transaction counts, balances, source-version
   evidence, and existing reconciliation links with the retained dry-run report.
6. Leave legacy tables untouched and restrict them from ordinary application
   writes. Their later archival requires a separate reviewed retention decision.

## Fresh installations

Fresh hosts install only the product package:

```bash
composer require fliix-cloud/filament-accounting
php artisan filament-accounting:install --migrate --country=DE
php artisan filament-accounting:verify
```

Register one plugin:

```php
$panel->plugin(
    \FilamentAccounting\FilamentAccountingPlugin::make()
);
```

Create the company in the German-first setup flow. The package seeds the default
ledger accounts, versioned tax rates, posting logic, expense categories, and
automatic bank ledger mapping internally.

## Rollback

If cutover verification fails, keep workers stopped, retain the reports, and
restore the pre-deployment database and storage snapshots before redeploying the
old packages. Do not run the old bridge against a database already receiving new
unified FinTS writes. Once new writes must be retained, rollback is a data
migration project and requires a new reviewed mapping; the retained legacy tables
alone are not sufficient.
