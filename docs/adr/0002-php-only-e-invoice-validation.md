# ADR 0002: PHP-only e-invoice validation

- Status: Accepted
- Date: 2026-09-02

## Context

`filament-accounting` is a Laravel and Filament package deployed in PHP-only
environments. The selected ZUGFeRD dependency also contains optional helper
classes that can download and invoke Java-based KoSIT or veraPDF tools. Using
those helpers would introduce a JVM, subprocess, network, and artifact supply
chain outside the supported application stack.

ZUGFeRD/Factur-X still requires a structured EN-16931 payload and the expected
PDF/A-3 container structures. XRechnung additionally requires the applicable
schema and business rules. Parser success or PDF metadata alone is not treated
as an independent conformity certificate.

## Decision

1. Runtime, Artisan commands, queue workers, scheduler tasks, automated tests,
   CI, and mandatory release gates use PHP only.
2. Application code must not instantiate `ZugferdKositValidator` or
   `ZugferdPdfValidator`, start a JVM, execute a JAR, or call a remote validation
   service.
3. Generated CII is validated before storage using the PHP-native reader, XSD
   validator, and document-rule validator supplied by `horstoeko/zugferd`.
4. XML input remains protected against DTD, XXE, and external resource
   resolution. Further XRechnung/EN-16931 Schematron coverage must use PHP XML
   extensions and versioned local rules.
5. Automated PDF checks verify structural evidence in PHP: PDF signature,
   PDF/A-3 XMP identification, conformance level, ICC/output intent, associated
   XML file relationship, and byte identity of embedded and separate XML.
6. Reports describe this as PHP-native structural validation, not as independent
   PDF/A certification.

## Consequences

- Production and CI require no Java installation.
- Validation stays deterministic and does not send invoice data to third-party
  services.
- Java-only reference validators are neither blockers nor release requirements.
- Any future claim of broader format conformity needs PHP-native tests against a
  versioned official corpus and rule set.
