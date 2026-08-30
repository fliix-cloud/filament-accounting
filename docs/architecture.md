# Architecture

`filament-accounting` is a Laravel package. Host applications consume it through Composer. Filament resources are registered only when `FilamentAccountingPlugin` is added to a panel.

## Layers

1. **Contracts** — `LedgerEngine`, ownership, bank-feed drivers, compliance profiles, e-invoice adapters, exporters.
2. **Domain services** — issue/register/post documents, finalize/reverse reconciliation, close periods. Business rules live here, not in Filament callbacks.
3. **First-party ledger** — `FirstPartyLedgerEngine` posts balanced, immutable journals.
4. **Filament** — localized resources that orchestrate services.

## Package boundaries

```
Host app
├── filament-accounting   (no FilamentFints references)
├── filament-fints        (no accounting references)
└── filament-accounting-fints  (bridge only)
```

Money is `FilamentAccounting\Support\ExactMoney` backed by `brick/money`. Persisted amounts are signed integer minor units.

## Source layout

```
src/
├── Banking/        DTOs and synthetic driver
├── Commands/
├── Compliance/     generic + Germany profiles
├── Documents/      e-invoice adapter
├── Export/         generic journal CSV (not DATEV)
├── Filament/
├── Ledger/
├── Models/
├── Ownership/
├── Reconciliation/
└── Services/
```
