# Germany compliance

Germany is registered as compliance profile `DE` (`GermanComplianceProfile`). Domain services must not branch on `if ($country === 'DE')`.

The profile seeds:

- A generic chart mapped through **semantic account roles** (not proprietary SKR content)
- Versioned VAT codes (19%, 7%, 0%, historical 16%/5%, intra-community,
  reverse charge, and export treatments)
- Internal posting rules and twelve understandable purchase expense categories

Relevant primary sources (verify current text before relying on them):

- [AO § 146](https://www.gesetze-im-internet.de/ao_1977/__146.html)
- [BMF E-Rechnung FAQ](https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html)
- GoBD materials from the Bundesministerium der Finanzen
- UStG / UStDV invoice requirements
- EN 16931 / ZUGFeRD / XRechnung specifications

The package lays foundations for complete records, immutability, audit logging, period locks (Festschreibung), reversal-based correction, and export architecture. It does **not** claim that installation equals GoBD certification.

The generic journal CSV exporter is **not** DATEV-compatible. DATEV export remains an `AccountingExporter` extension point.

## GoBD readiness roadmap

The unified product implementation and audit roadmap is maintained in the
[GoBD readiness and audit master plan](GOBD_COMPLIANCE_MASTER_PLAN.md). It is the
source of truth for the `ACC-*`, protocol-boundary, transition, and host controls.
The historical three-package boundary was superseded by
[ADR 0003](adr/0003-unified-accounting-package.md).

Merging the roadmap does not establish compliance. Each control must be implemented,
tested, evidenced in a defined reference installation, and independently reviewed
within the documented scope.
