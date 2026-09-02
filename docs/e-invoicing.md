# E-invoicing

`EInvoiceAdapter` parses and generates structured invoices. `ZugferdEInvoiceAdapter` wraps `horstoeko/zugferd`.

Rules:

- Store the original XML unchanged with SHA-256
- MIME type is detected from content
- Structured payload remains discoverable after posting
- Generation uses the issued document snapshot, not later catalog edits
- Runtime, CLI commands, queues, tests, CI, and release gates are PHP-only. They
  must not start a JVM, execute JAR files, or use the Java-backed KoSIT/veraPDF
  wrappers shipped as optional facilities by dependencies.
- Generated CII is parsed and checked with the PHP-native
  `ZugferdXsdValidator` and `ZugferdDocumentValidator` before it is stored.
- PDF checks remain PHP-native and cover the PDF signature, PDF/A-3 XMP marker,
  output intent, associated-file relationship, and byte-identical XML payload.
  These structural checks do not claim independent PDF/A certification.
- Additional XRechnung/EN-16931 Schematron rules must be executed with PHP XML
  facilities and versioned local rule artifacts. Remote resolution and external
  validation services are not permitted.

Incoming XML is parsed to an `EInvoiceParseResult` (seller, amounts, lines, hash, validity). Hosts still review before `RegisterPurchaseInvoice`.

See [ADR 0002](adr/0002-php-only-e-invoice-validation.md) for the binding
technology boundary and its consequences.
