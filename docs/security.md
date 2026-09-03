# Security

- Attachments default to a private disk (`local`). Public disks are rejected.
- MIME type comes from file content (`finfo`), not the client.
- Audit payloads redact secrets (`pin`, `tan`, `password`, `token`, …).
- Authorization is checked in services, navigation, and actions. UI hiding is not a security boundary.
- Owners are resolved from trusted context, never from untrusted request parameters.
- Idempotency keys, row locks, and unique constraints protect against duplicate issue/post/finalize.
- Do not log full invoices, bank credentials, or raw sensitive payloads.
- FinTS endpoints are HTTPS-only by default and reject private/unapproved hosts.
- PIN, user identifiers, dialog state, SCA challenge data, and resumable payment
  state remain encrypted; ambiguous submissions are never retried automatically.
- SCA challenge responses are Legal Entity and ability scoped, non-cacheable,
  `nosniff`, same-origin, and sandbox SVG content through CSP.
- FinTS queue jobs carry scalar IDs and activate the trusted Legal Entity context
  before loading bank records.
