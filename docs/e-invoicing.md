# E-invoicing

`EInvoiceAdapter` parses and generates structured invoices. `ZugferdEInvoiceAdapter` wraps `horstoeko/zugferd`.

Rules:

- Store the original XML unchanged with SHA-256
- MIME type is detected from content
- Structured payload remains discoverable after posting
- Generation uses the issued document snapshot, not later catalog edits

Incoming XML is parsed to an `EInvoiceParseResult` (seller, amounts, lines, hash, validity). Hosts still review before `RegisterPurchaseInvoice`.
