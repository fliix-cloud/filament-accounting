# filament-accounting

Laravel 13 / Filament v5 double-entry accounting package with a first-party ledger, German-first compliance profiles, bank reconciliation, and e-invoice storage.

This repository is an **installable Composer package**, not a Laravel application.

## What it is

- Legal-entity scoped double-entry journal (`LedgerEngine`)
- Customers, suppliers, catalog, sales and purchase invoices, open items
- Canonical bank statement lines with direct assignment, partial settlement, and true multi-target splits
- Versioned posting rules (“Steuerfall”) and tax rule versions
- German and generic compliance profiles (Germany is a registered profile, not a hard-coded `if ($country === 'DE')`)
- German and English UI translations
- Exact money via `brick/money` (integer minor units, no floats)

It does **not** require `filament-fints`. Bank feeds are pluggable drivers. FinTS mapping belongs in `fliix-cloud/filament-accounting-fints`.

Installing this package does not make a host “GoBD certified”. Compliance also depends on deployment, permissions, backups, retention, and procedure.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament v5

## Installation

Until a Packagist release exists, require from VCS or a path repository:

```bash
composer require fliix-cloud/filament-accounting:dev-main
php artisan filament-accounting:install --migrate
php artisan filament-accounting:seed-profile DE
php artisan filament-accounting:verify
```

Register the plugin on a Filament panel:

```php
use FilamentAccounting\FilamentAccountingPlugin;

$panel->plugin(
    FilamentAccountingPlugin::make()
        ->customers()
        ->salesInvoices()
        ->bankReconciliation()
);
```

Resolve the current `LegalEntity` from trusted application context (`ConfiguredLegalEntityResolver`, config `ACCOUNTING_LEGAL_ENTITY_ID`, or a host binding). Never take it from an untrusted request parameter.

## Documentation

- [Architecture](docs/architecture.md)
- [Domain model](docs/domain-model.md)
- [Ledger invariants](docs/ledger-invariants.md)
- [Bank reconciliation](docs/bank-reconciliation.md)
- [Ownership and tenancy](docs/ownership-tenancy.md)
- [Germany compliance](docs/germany-compliance.md)
- [E-invoicing](docs/e-invoicing.md)
- [Security](docs/security.md)
- [Local development](docs/LOCAL_DEVELOPMENT.md)
- [Operations and retention](docs/operations-and-retention.md)
- [ADR: ledger engine](docs/adr/0001-ledger-engine.md)

## License

MIT. See [LICENSE](LICENSE).
