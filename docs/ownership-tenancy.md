# Ownership and tenancy

```text
Owner ≠ Actor ≠ Membership
```

`LegalEntity` is the accounting reporting and integrity boundary. Every application instance contains exactly one company, resolved directly from the database:

- `SingleLegalEntityResolver` (the single company configured for the application instance)
- `AuthenticatedUserAccountingActorResolver`
- `NullAccountingTenancyContextActivator` (queue compatibility hook; no tenant switching)
- `DefaultAccountingAuthorizer` (Laravel Gate)
- `LegalEntityScope` — query helper, **not** a hidden Auth global scope

Never accept `legal_entity_id` from request input without authorization and server-side validation.

Bank connections, canonical accounts/transactions, transfers, direct debits,
mandates, documents, reconciliations, settlements, journals, and learning rules
all resolve to this same boundary. The former FinTS owner morph is migrated to
`legal_entity_id`; no `LegalEntityOwnerMapper` remains.

Queued work carries the company ID as a durable integrity check. The application does not expose tenant switching or multi-company selection.
