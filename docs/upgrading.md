# Schema and release policy

## Current development contract

The package is pre-release. No persistent installation baseline or supported
upgrade matrix has been established. The schema and public API may change,
including changes to previously shipped base migrations. There is no production
or GoBD release approval; see [GoBD readiness](gobd.md).

As of 12 September 2026, `database/migrations` contains nine migrations: three
table-creation migrations and six forward alterations (reconciliation tax rule,
party contacts, catalog purchase price/EAN, invoice versions, invoice payment
and layout fields, and FinTS requested sync range). Laravel loads them directly
from the package via its service provider. Do not publish a second copy.

Forward migrations preserve data for their expected starting schema. They do
not repair every historical development schema: Laravel does not rerun a base
migration that is already recorded as executed. An empty migration queue alone
does not prove schema compatibility.

## Updating an existing development host

1. Record the source and target package commits, host `composer.lock`, database
   engine/version, and `php artisan migrate:status`. Compare migration changes
   between those exact commits, including already executed base migrations.
2. Back up the database, private originals/artifacts, audit anchors, and required
   encryption keys together. Verify restoration into an isolated environment.
3. If the starting schema matches and all changes are forward migrations, test
   the update on that restored copy with `php artisan migrate`. This runs pending
   host migrations too. Run the package verification commands and check record
   counts, balances, invoice versions, artifacts, and audit evidence.
4. If an executed base migration changed, retained data requires a separately
   designed and tested migration/backfill. Stop the update until that exists.
   Do not fabricate historical audit evidence to satisfy the new schema.
5. Only disposable development/demo databases may be rebuilt with
   `php artisan migrate:fresh --seed`; that command drops tables, including host
   tables. Never use it as an upgrade command for retained accounting data.

Rollback is not generally lossless. Invoice-version and payment-snapshot
migrations deliberately refuse some rollbacks once new evidence exists. Plan
recovery with the matching application code and a verified backup; account for
any external bank submissions before retrying operations after restoration.

## Plugin compatibility changes in Unreleased

- `chart_of_accounts` and `audit` now register their resources when enabled;
  both remain disabled in the default config.
- `tax_and_posting_rules` now owns both tax codes and posting rules, independently
  of `settings`. Posting rules become visible by default to users with
  `manage_chart`. Hosts that previously used `settings(false)` to hide tax codes
  must also use `taxAndPostingRules(false)` if they want both hidden.
- Every remaining `features` key has a matching fluent method. Configuration
  `false` takes precedence over fluent `true`; these are UI registration controls.
- Remove the obsolete, previously unused `features.reports` entry from published
  host config. Journal, chart, and audit independently populate that nav group.
- The bank balances widget follows `bank_reconciliation`.
- Panel width is host-owned by default. Opt into the previous layout with
  `FilamentAccountingPlugin::make()->fullWidth()`. The workbench opts in.
- The plugin registers two custom bank-amount colors, `accounting-negative` and
  `accounting-positive`. It does not replace Filament's standard color names.

Merge published host config deliberately; do not overwrite host credentials,
authorization mappings, or ownership settings with `vendor:publish --force`.

## First 0.x release requirements

Dataset exports now require a supported snapshot connection: MySQL/MariaDB with
InnoDB tables, or SQLite. They explicitly reject other drivers rather than
inherit an unverified isolation mode. The change adds no schema migration and
does not change export container versions or schema revision. MySQL's session
isolation default remains unchanged; only export transactions use repeatable reads.

The first tagged release must identify its supported schema baseline, exact
dependency state, supported database engines, and remaining limitations.
Version numbers come from Git tags; `Unreleased` remains the changelog heading
until a release is actually made.

For the supported baseline, keep executed migrations immutable and add forward
migrations with tests against populated previous-version databases. Each release
must document breaking API/config/schema changes, backfills, rollback limits,
and recovery checks. During 0.x, incompatible changes require a minor-version
increment and explicit upgrade notes; patch releases must preserve the declared
baseline contract. A 0.x tag alone is not a production or compliance approval.

`nemiah/php-fints:dev-master` remains an accepted development risk (S-15).
The local review baseline resolves to
`9d66a1bf92d30c3f3054a8158ea5de5bd75bb10a`. The stable 4.1.0 release points to
`845c8cc973310c11ae2441e88e118c505d1b59f1`. The
[upstream comparison](https://github.com/nemiah/phpFinTS/compare/845c8cc973310c11ae2441e88e118c505d1b59f1...9d66a1bf92d30c3f3054a8158ea5de5bd75bb10a)
includes configurable User-Agent support used by `PhpFintsClientFactory`, as well
as later SEPA and CAMT fixes. Changing to 4.1.0 therefore requires compatibility
work and banking regression validation; it is not merely a reproducibility fix.
Retain and deploy the host lockfile; do not run an unrestricted dependency update
as part of deployment. A compatible tagged protocol release must be evaluated
before a production claim. Appending a commit to the library's own requirement
is insufficient: Composer's explicit commit references are
[root-only and ignored in dependencies](https://getcomposer.org/doc/04-schema.md#package-links).
