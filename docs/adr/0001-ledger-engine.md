# ADR 0001 — First-party ledger engine (reject eloquent-ifrs)

## Status

Accepted (2026-08-30)

## Context

The specification required evaluating `ekmungai/eloquent-ifrs` as a possible internal ledger. A `LedgerEngine` contract is mandatory regardless of implementation.

## Evidence against eloquent-ifrs

Inspected current upstream code (`https://github.com/ekmungai/eloquent-ifrs`):

1. **Auth coupling.** `Segregating` / entity global scopes resolve `Auth::user()->entity`. Background jobs and host-resolved `LegalEntity` context cannot safely post without forging an authenticated user.
2. **Host User pollution.** The package’s `IFRSUser` trait expects accounting columns on the host `users` table (`entity_id` and related IFRS fields). This package must not modify host identity tables.
3. **Float money.** `LineItem` and related APIs type amounts as `float`. That violates the exact-money rule (`brick/money`, integer minor units, reject scale mismatch).
4. **Recyclable deletes.** IFRS uses recyclable/soft-delete patterns that are not an accounting correction. This package requires linked reversals and posted immutability.
5. **Tenancy.** Global entity scopes keyed off `Auth::user()` are incompatible with multi-database tenancy and Owner ≠ Actor ≠ Membership.

## Decision

Implement `FirstPartyLedgerEngine` behind `FilamentAccounting\Contracts\LedgerEngine`. Do not depend on `ekmungai/eloquent-ifrs`.

## Consequences

- Full control of immutability, idempotency, period locks, and exact money
- No IFRS table ownership or user-table migrations
- Reports and exotic IFRS features are out of v1 scope unless added behind the same contract
