# Ownership and tenancy

```text
Owner ≠ Actor ≠ Membership
```

`LegalEntity` is the accounting reporting and integrity boundary. The current entity is resolved from trusted application context:

- `ConfiguredLegalEntityResolver` (bound entity, `ACCOUNTING_LEGAL_ENTITY_ID`, or UUID)
- `AuthenticatedUserAccountingActorResolver`
- `NullAccountingTenancyContextActivator` (hosts replace this for multi-database tenancy)
- `DefaultAccountingAuthorizer` (Laravel Gate)
- `LegalEntityScope` — query helper, **not** a hidden Auth global scope

Never accept `legal_entity_id` from request input without authorization and server-side validation.

Bank connections, canonical accounts/transactions, transfers, direct debits,
mandates, documents, reconciliations, settlements, journals, and learning rules
all resolve to this same boundary. The former FinTS owner morph is migrated to
`legal_entity_id`; no `LegalEntityOwnerMapper` remains.

Queued work must carry durable scalar identity and activate tenancy before querying tenant tables. This package does not rely on cross-database foreign keys.
