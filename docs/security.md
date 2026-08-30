# Security

- Attachments default to a private disk (`local`). Public disks are rejected.
- MIME type comes from file content (`finfo`), not the client.
- Audit payloads redact secrets (`pin`, `tan`, `password`, `token`, …).
- Authorization is checked in services, navigation, and actions. UI hiding is not a security boundary.
- Owners are resolved from trusted context, never from untrusted request parameters.
- Idempotency keys, row locks, and unique constraints protect against duplicate issue/post/finalize.
- Do not log full invoices, bank credentials, or raw sensitive payloads.
