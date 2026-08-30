# Germany compliance

Germany is registered as compliance profile `DE` (`GermanComplianceProfile`). Domain services must not branch on `if ($country === 'DE')`.

The profile seeds:

- A generic chart mapped through **semantic account roles** (not proprietary SKR content)
- Versioned VAT codes (19%, 7%, exempt, reverse charge)
- Posting rules with familiar German labels (Steuerfälle)

Relevant primary sources (verify current text before relying on them):

- [AO § 146](https://www.gesetze-im-internet.de/ao_1977/__146.html)
- [BMF E-Rechnung FAQ](https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html)
- GoBD materials from the Bundesministerium der Finanzen
- UStG / UStDV invoice requirements
- EN 16931 / ZUGFeRD / XRechnung specifications

The package lays foundations for complete records, immutability, audit logging, period locks (Festschreibung), reversal-based correction, and export architecture. It does **not** claim that installation equals GoBD certification.

The generic journal CSV exporter is **not** DATEV-compatible. DATEV export remains an `AccountingExporter` extension point.
