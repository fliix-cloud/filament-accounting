# filament-accounting

Laravel 13 / Filament v5 accounting package with a first-party ledger, German-first invoicing, integrated FinTS banking, reconciliation, and e-invoice storage.

This repository is an **installable Composer package**, not a Laravel application.

## What it is

- Legal-entity scoped double-entry journal (`LedgerEngine`)
- Customers, suppliers, catalog, sales and purchase invoices, open items
- One canonical bank account and bank transaction model, direct FinTS synchronization, SEPA transfers/direct debits, mandates, and SCA
- Append-only bank source versions for pending, booked, changed, and reversed source states
- Direct assignment, partial settlement, true multi-target splits, and explainable local learning rules
- Versioned tax rates and internal posting rules; German 19%, 7%, 0%, historical 16%/5%, EU and export treatments
- Upload-first purchase invoices with a required business category and automatic internal ledger mapping
- German and English UI translations
- Exact money via `brick/money` (integer minor units, no floats)

Applications install and register only this package. The framework-free
[`nemiah/php-fints`](https://github.com/nemiah/phpFinTS) is the transitive,
framework-independent protocol dependency. All Laravel,
Filament, persistence, tenancy, and banking workflows live in this package.

Installing this package does not make a host “GoBD certified”. Compliance also depends on deployment, permissions, backups, retention, and procedure.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament v5

## Installation

Install the product package and its migrations:

```bash
composer require fliix-cloud/filament-accounting
php artisan filament-accounting:install --migrate --country=DE
php artisan filament-accounting:verify
```

For tamper-evident audit-chain anchors outside the application database, configure independently controlled immutable/versioned storage, then run:

```bash
php artisan filament-accounting:audit-anchor --json
php artisan filament-accounting:verify --json
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --json
```

See [Operations](docs/operations.md) for the required trust boundary,
configuration, scheduling, and residual risks.

Register the plugin on a Filament panel:

```php
use FilamentAccounting\FilamentAccountingPlugin;

$panel->plugin(
    FilamentAccountingPlugin::make()
);
```

The package uses one `LegalEntity` per application instance. `SingleLegalEntityResolver` resolves that company directly from the database; record ownership is never taken from an untrusted request parameter.

Configure `FINTS_PRODUCT_ID` before creating a bank connection. The integrated
commands are `filament-accounting:sync-institutes`,
`filament-accounting:sync-bank`, and `filament-accounting:cleanup-sca`.

The project is pre-release. Development databases use the final schema directly;
after schema changes, recreate them with `php artisan migrate:fresh --seed`.
There is no data-upgrade or consolidation workflow.

## Documentation

- [Architecture](docs/architecture.md) — scope, boundaries, accounting rules,
  reconciliation, e-invoices, and extension points
- [Operations](docs/operations.md) — production responsibilities, audit anchors,
  retention, recovery, and release checks
- [GoBD readiness](docs/gobd.md) — code assessment, release blockers, and the
  conditions for a defensible compliance claim

## Development

```bash
composer install
composer check
vendor/bin/testbench serve
```

The workbench panel is available at `/admin`. On Windows, run
`scripts/setup-herd-demo.ps1` to link the package into the optional Herd demo.

## License

MIT. See [LICENSE](LICENSE).
