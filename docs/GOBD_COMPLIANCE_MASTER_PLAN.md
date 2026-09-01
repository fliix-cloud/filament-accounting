# GoBD-Readiness- und Prüfplan für die drei Filament-Repositories

**Stand:** 1. September 2026  
**Geltungsbereich:**

- [`fliix-cloud/filament-fints`](https://github.com/fliix-cloud/filament-fints), Branch `master`, geprüfter Stand `2a9e1da20e1a4dfa8f6fff4052ed91bdabe23f62`
- [`fliix-cloud/filament-accounting`](https://github.com/fliix-cloud/filament-accounting), Branch `main`, geprüfter Stand `db49f2078d09b0b1b2dd34eea0bf508dbc211ca8`
- [`fliix-cloud/filament-accounting-fints`](https://github.com/fliix-cloud/filament-accounting-fints), Branch `main`, geprüfter Stand `40cc6be09ef15f9567fd425599afc6dcaea09965`

> Dieser Plan ist eine technische und organisatorische Compliance-Roadmap, keine Rechts- oder Steuerberatung. Rechtsgrundlagen, Aufbewahrungsentscheidungen und der endgültige Prüfungsumfang müssen vor Freigabe mit Steuerberatung und unabhängigem Wirtschaftsprüfer bzw. IT-Prüfer bestätigt werden.

---

## 1. Ergebnis in einem Satz

Das realistische Ziel lautet nicht „amtlich GoBD-zertifiziert“, sondern:

1. ein **GoBD-unterstützendes Softwareprodukt** mit nachvollziehbaren Kontrollzielen,
2. eine **GoBD-konforme Referenzinstallation und Betriebsorganisation**,
3. eine **unabhängige Softwareprüfung, vorzugsweise nach IDW PS 880**, und
4. bei einem durch fliix betriebenen SaaS zusätzlich eine Prüfung des dienstleistungsbezogenen IKS, beispielsweise nach **IDW PS 951**.

Die Finanzverwaltung erteilt keine Positivtestate. Zertifikate oder Testate Dritter können nützlich sein, entfalten gegenüber der Finanzbehörde aber keine Bindungswirkung. Verantwortlich bleibt das Unternehmen, das die Software einsetzt.

### Empfohlene spätere Produktformulierung

Nicht verwenden:

> „GoBD-zertifizierte Buchhaltung“

Besser und prüfbar:

> „Die Version X des Softwareprodukts wurde für den im Prüfbericht beschriebenen Funktions- und Konfigurationsumfang unabhängig nach IDW PS 880 geprüft. Ein GoBD-konformer Betrieb setzt die dokumentierten organisatorischen und technischen Maßnahmen der einsetzenden Organisation voraus.“

---

## 2. Aktuelle Rechts- und Prüfungsbasis

### 2.1 Verbindliche Basis für das Deutschland-Profil

Der Prüf- und Entwicklungsstand muss mindestens folgende Quellen berücksichtigen:

| Quelle | Bedeutung für das Projekt |
| --- | --- |
| [GoBD vom 28. November 2019, geändert 2024](https://amtliche-handbuecher.bundesfinanzministerium.de/ao/2025/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/anhang-33.html) | Vollständigkeit, Richtigkeit, Zeitgerechtigkeit, Ordnung, Unveränderbarkeit, Belegfunktion, IKS, Aufbewahrung, Verfahrensdokumentation und Datenzugriff |
| [Zweite GoBD-Änderung vom 14. Juli 2025](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.pdf?__blob=publicationFile&v=4) | E-Rechnungen, strukturierter Rechnungsteil, Originalformat, Z2-Datenzugriff; anzuwenden seit 14. Juli 2025 |
| [§§ 145–147 AO](https://www.gesetze-im-internet.de/ao_1977/) | Gesetzliche Anforderungen an Ordnung, Änderungsschutz, Aufbewahrung und Datenzugriff |
| [§§ 238–239 und 257 HGB](https://www.gesetze-im-internet.de/hgb/) | Handelsrechtliche Buchführung, Unveränderbarkeit und Aufbewahrung |
| [§§ 14 und 14b UStG](https://www.gesetze-im-internet.de/ustg_1980/) | Rechnungsanforderungen und achtjährige Rechnungsaufbewahrung |
| [BMF-FAQ E-Rechnung, Stand März 2026](https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html) | Aktuelle Verwaltungsauffassung zu Empfang, Formaten, Übergangsfristen und Aufbewahrung |
| [IDW PS 880 n.F. (01.2022)](https://www.idw.de/idw/idw-verlautbarungen/idw-eps-880-n-f-03-2021.html) | Prüfung eines Softwareprodukts |
| [IDW PS 951 n.F. (03.2021)](https://www.idw.de/idw/idw-verlautbarungen/idw-ps-951-n-f-03-2021.html) | Prüfung des IKS eines Dienstleistungsunternehmens, relevant bei SaaS-/Managed-Betrieb |

Die Rechtsquellen werden als versionierter Compliance-Datensatz geführt. Mindestens einmal pro Quartal und vor jedem zertifizierungsrelevanten Release wird geprüft, ob Änderungen eingetreten sind.

### 2.2 GoBD-Kernanforderungen als technische Kontrollziele

| GoBD-Prinzip | Technisches Ziel |
| --- | --- |
| Nachvollziehbarkeit und Nachprüfbarkeit | Jeder Vorgang ist progressiv vom Originalbeleg bis zur Auswertung und retrograd von der Auswertung bis zum Originalbeleg verfolgbar. |
| Vollständigkeit | Kein relevanter Beleg, Import, Buchungssatz, Split, Storno oder Verarbeitungsschritt kann unbemerkt fehlen. |
| Richtigkeit | Beträge, Währungen, Steuern, Kontierungen, Perioden und Beziehungen werden validiert und exakt verarbeitet. |
| Zeitgerechte Erfassung | Entstehungs-, Eingangs-, Erfassungs-, Buchungs-, Festschreibungs- und Änderungszeitpunkte sind getrennt dokumentiert; Fristverstöße werden sichtbar. |
| Ordnung | Eindeutige IDs, Sequenzen, Perioden, Belegarten und Verknüpfungen erlauben Suche, Sortierung und Prüfung. |
| Unveränderbarkeit | Ursprüngliche Inhalte bleiben erhalten. Änderungen erfolgen versioniert, protokolliert oder als Gegen-/Stornobuchung. |
| Belegprinzip | Jede Buchung besitzt einen Beleg oder einen nachvollziehbaren Eigenbeleg; Buchung und Beleg sind dauerhaft verknüpft. |
| IKS und Datensicherheit | Zugriffe, Funktionstrennung, Plausibilitäts-, Übertragungs-, Abstimmungs- und Verarbeitungskontrollen werden ausgeübt und protokolliert. |
| Aufbewahrung und Lesbarkeit | Daten und Originaldokumente bleiben während der Frist verfügbar, verständlich, lesbar und maschinell auswertbar. |
| Datenzugriff | Z1, Z2 und Z3 können im rechtlich erforderlichen Umfang bereitgestellt werden. |
| Verfahrensdokumentation | Allgemeine Beschreibung, Anwender-, System- und Betriebsdokumentation entsprechen versioniert dem real eingesetzten Stand. |

### 2.3 Aufbewahrungsmatrix als konfigurierbare Policy

Keine globale `retention_years = 10`-Konstante verwenden. Die rechtliche Klassifikation ist maßgeblich.

| Kategorie | Gesetzlicher Ausgangspunkt | Empfohlene Systembehandlung |
| --- | --- | --- |
| Bücher, Journale, Konten, Inventare, Jahresabschlüsse und zum Verständnis nötige Organisationsunterlagen | regelmäßig 10 Jahre | Unveränderbar halten; Verfahrensdokumentation und Schemainformationen an denselben Zeitraum koppeln. |
| Buchungsbelege, ein- und ausgehende Rechnungen | regelmäßig 8 Jahre | Originalformat und strukturierte Daten erhalten; Beziehungen zur Buchung beibehalten. |
| Empfangene und abgesandte Handels-/Geschäftsbriefe | regelmäßig 6 Jahre | Original und steuerlich relevante Übermittlungsmetadaten erhalten. |
| Audit-, Konfigurations- und Programmidentitätsnachweise | abhängig von den damit erklärten Daten | Mindestens so lange halten, wie ein damit erklärter Datensatz aufbewahrt werden muss. |
| Bankdaten | nach ihrer tatsächlichen Funktion als Grundaufzeichnung, Buchungsbeleg oder Nebenbuch | Pro Datentyp klassifizieren; nicht pauschal nur nach Herkunft behandeln. |

Ein Löschlauf darf erst nach Ablauf aller einschlägigen Fristen, Sperren, Rechtsbehelfs- und Prüfungszeiträume erfolgen. Ein `legal hold` muss jede automatische Löschung verhindern. Die finale Matrix wird durch Steuerberatung bzw. Prüfer freigegeben.

### 2.4 Abgrenzung Kasse/TSE

Elektronische Buchhaltungsprogramme sind nach § 1 KassenSichV nicht allein deshalb elektronische Aufzeichnungssysteme im Sinne des § 146a AO. Für den aktuellen Umfang wird daher **keine TSE- oder DSFinV-K-Funktion eingeplant**.

Sobald eine echte Kassenfunktion, Registrierkasse, Barverkaufsoberfläche oder vergleichbare Grundaufzeichnung hinzukommt, ist der Scope neu zu bewerten. Ein schlichtes Buchhaltungskonto „Kasse“ ist nicht automatisch eine Registrierkasse.

---

## 3. Architekturgrenze der drei Repositories

### 3.1 `filament-accounting` – führendes System

Verantwortlich für:

- Rechnung und Originalbeleg,
- Grundaufzeichnung und unveränderliche Fassung,
- Journal, Konten und offene Posten,
- Steuer- und Buchungsregelversionen,
- Bankabstimmung, Split und Settlement,
- Audit- und Änderungshistorie,
- Aufbewahrungsstatus und Legal Holds,
- Z1-/Z2-Prüfoberfläche und Z3-Export,
- Deutschland-Profil sowie generische internationale Erweiterungspunkte.

Die buchhalterische Wahrheit darf nicht von der späteren Verfügbarkeit der FinTS-Verbindung abhängen.

### 3.2 `filament-fints` – Bank-Vor-/Nebensystem

Verantwortlich für:

- FinTS-Kommunikation, SCA und Zugangsschutz,
- Bankkonten und von der Bank gelieferte Umsätze,
- Importläufe, Quellidentität, Roh-/Originaldaten bzw. beweissichere Quellsnapshots,
- Nachweis von Pending→Booked-, Storno- und sonstigen Bankänderungen,
- idempotente Synchronisation und Zahlungsinitiierung.

Es führt keine Buchhaltungskonten, Steuerfälle oder Journale.

### 3.3 `filament-accounting-fints` – Bridge/Anti-Corruption-Layer

Verantwortlich für:

- Owner-/Legal-Entity-Mapping,
- nachvollziehbare und versionierte Transformation,
- Übergabe von Quellidentität, Hashes, Versionen und Kontrollsummen,
- idempotente Wiederholung, Fehler- und Wiederanlaufnachweise.

Die Bridge darf weder eigene Buchhaltungswahrheit noch eigene FinTS-Protokollregeln besitzen.

---

## 4. Bestandsaufnahme

### 4.1 Bereits gute Grundlagen

#### `filament-accounting`

- Echte doppelte Buchführung mit `JournalEntry` und `JournalLine`.
- Exakte Geldverarbeitung über Minor Units und `brick/money`.
- Prüfung auf mindestens zwei Buchungszeilen und ausgeglichene Soll-/Haben-Summen.
- Idempotenzschlüssel, Datenbanktransaktionen und Zeilensperren.
- Periodensperren und Storno-/Umkehrbuchungen statt Überschreiben gebuchter Journale.
- Dokumentstatus, Belegnummernsequenzen und Snapshots von Geschäftspartnerdaten.
- Versionierte Steuer- und Posting-Regeln.
- Originalanhänge mit SHA-256, MIME-Erkennung aus dem Inhalt und privatem Storage.
- Audit-Modell und Audit-Oberfläche.
- Mandanten-/Legal-Entity-Isolation und serverseitige Resolver.
- E-Rechnungs-Adapter und ZUGFeRD-Bibliothek.
- Bankabgleich mit direkter Zuordnung, Teilzahlung, Split und Storno.

#### `filament-fints`

- Verschlüsselte Zugangsdaten und SCA-Zustände.
- Redaction und Schutz vor unsicheren FinTS-Endpunkten.
- Owner-Isolation und fail-closed Tenancy.
- Transaktionsfingerprints und Vorkehrungen gegen Duplikate.
- Idempotente Zahlungsaufträge und Behandlung unklarer Bankantworten.
- Sync-Run- und Banktransaktionsmodelle.

#### `filament-accounting-fints`

- Saubere Paketgrenze ohne direkte Fremdschlüssel zwischen Accounting und FinTS.
- Owner-Mapping mit fail-closed Verhalten.
- Idempotenter Import und Recovery-Command.
- Normalisierter Quellpayload mit Hash.
- Accounting-Kopie bleibt auch nach Entfernung des Connectors bestehen.

### 4.2 Wesentliche Lücken

#### Kritisch – vor einer externen Prüfung zu schließen

1. **ORM-Schutz ist keine vollständige Unveränderbarkeit.** Eloquent-Events schützen ausgegebene Dokumente und gebuchte Journale, können aber durch Query Builder, SQL, fehlerhafte Migrationen oder privilegierte Zugriffe umgangen werden.
2. **Audit-Events sind selbst veränder- und löschbar.** Das Audit-Modell besitzt derzeit weder Immutable Guard noch Sequenz/Hash-Kette oder externen Integritätsanker.
3. **Anhänge sind nicht beweissicher archiviert.** Ein SHA-256 wird gespeichert, aber die Datei kann auf dem Storage ausgetauscht oder gelöscht werden. Die aktuelle Integritätsprüfung kontrolliert sie nicht.
4. **Bankquellen werden überschrieben.** Pending→Booked in FinTS und normale Reimporte in Accounting aktualisieren Datensätze. Frühere Zustände und vollständige Importantworten bleiben nicht lückenlos erhalten.
5. **Folgedatensätze sind nicht durchgehend geschützt.** `Reconciliation`, Splits, Settlements, Periodenereignisse, Posting-/Tax-Versionen und einige Stammdatenhistorien benötigen einheitliche Unveränderbarkeits- bzw. Versionierungsregeln.
6. **Der Prüfexport ist unzureichend.** `GenericJournalCsvExporter` exportiert nur Journalzeilen und ist weder ein vollständiger Z3-Export noch DATEV-kompatibel.
7. **Die Verfahrensdokumentation ist zu kurz.** Die vorhandenen Dokumente sind gute Hinweise, bilden aber noch keine vollständige allgemeine Beschreibung, Anwender-, System- und Betriebsdokumentation mit Versionsidentität.
8. **Programmidentität und Change Management fehlen als Evidenz.** Die Standardbranches sind nicht geschützt. Release, Konfiguration, Migrationen, Abhängigkeiten und tatsächlich eingesetzte Version sind noch nicht revisionsfest miteinander verknüpft.

#### Hoch

9. **`filament-accounting:verify` prüft nur Salden und Mindestzeilen.** Hashes, Sequenzen, Beziehungen, Zeitlogik, Perioden, Dokumente, Bankimporte und Storage fehlen.
10. **E-Rechnung ist noch nicht vollständig prüfbar.** Es fehlen ein verbindlicher Validierungsprozess, XRechnung-Abdeckung, Validator-/Regelversionsnachweis, Transport-/Empfangsmetadaten sowie die klare Trennung von Original, Visualisierung und extrahierten Daten.
11. **Ausgangsrechnungen benötigen einen dauerhaften Ausgabesnapshot.** Firmenbogen, AGB, Renderer-, Sprach-, Profil- und Templateversion müssen bei der Ausgabe reproduzierbar oder als Originalartefakt gespeichert sein.
12. **Perioden-Wiederöffnung überschreibt Zustandsfelder.** Ein Audit-Event existiert, aber mehrere Schließ-/Wiederöffnungszyklen benötigen eine eigene append-only Transition-Historie.
13. **Es fehlt ein vollständiges IKS-Kontrollregister.** Berechtigungen sind vorhanden, aber Funktionstrennung, Vier-Augen-Fälle, regelmäßige Zugriffskontrolle und nachweisbare Kontrollausführung sind nicht formalisiert.
14. **Aufbewahrung und Löschung sind Host-Verantwortung ohne ausführbaren Vertrag.** Es fehlen maschinenlesbare Policies, Legal Holds, dokumentierte Löschläufe und prüfbare Deployment-Anforderungen.

#### Mittel

15. `BankTransaction::signedAmount()` verwendet für Darstellung einen Float; fachlich relevante Wege sollen durchgehend exakte Decimal-/Money-Werte nutzen.
16. FinTS-Sync-Runs sind Statuszeilen, aber keine vollständigen, manipulationsgeschützten Importprotokolle mit Kontrollsummen.
17. Der Bridge-Hash bezieht sich auf einen normalisierten Teilpayload; Mapperversion, Rohquellreferenz, Transformationsschema und Kontrollsummen fehlen.
18. Backup, Restore, Zeitsynchronisation, Schlüsselverwaltung und Notfallbetrieb sind nur knapp beschrieben und nicht durch regelmäßige Testnachweise belegt.

---

## 5. Zielarchitektur: Compliance Evidence Spine

Die drei Pakete erhalten keine „Blockchain“. Stattdessen wird eine verständliche, testbare Evidenzkette aufgebaut.

### 5.1 Append-only Audit-Kette

`accounting_audit_events` wird erweitert oder durch ein neues append-only Ledger ergänzt:

- fortlaufende Sequenz pro `legal_entity_id`,
- `event_schema_version`,
- kanonisch serialisierter Payload,
- `previous_hash`,
- `event_hash`,
- fachlicher und technischer Zeitpunkt,
- Actor, Impersonator, Request, Correlation und Causation,
- Anwendungsversion, Commit-/Release-ID und Konfigurationssnapshot-ID,
- Grund bei manuellen oder privilegierten Vorgängen.

Die Kette erkennt Änderungen, verhindert aber allein keinen Angreifer mit vollständigem Datenbankzugriff. Deshalb wird der aktuelle Kettenkopf regelmäßig außerhalb derselben Datenbank verankert:

- in einem unveränderbaren/versionsgeschützten Objektspeicher oder
- durch eine Signatur mit einem Schlüssel, auf den der normale App-/DB-Betrieb keinen Zugriff besitzt.

Die Verifikation muss offline möglich sein. Hashalgorithmus, Kanonisierung und Schlüsselwechsel werden versioniert dokumentiert.

### 5.2 Append-only Fachzustände

Nach dem fachlichen Festschreibepunkt werden Daten nicht in-place verändert:

- Dokumentkorrektur durch Korrektur-/Stornodokument mit Rückbezug,
- Journaländerung durch Umkehr- und Neubuchung,
- Settlement-/Reconciliation-Korrektur durch explizite Reversal-Datensätze,
- Steuer-/Posting-Regeln durch neue Version,
- Periodenstatus durch `accounting_period_events`,
- Bankquelländerungen durch `*_versions` bzw. Source-Snapshot-Ereignisse,
- Stammdatenänderungen durch wirksame Versionen oder im Dokument gespeicherte Snapshots.

Entwurfsdaten dürfen editierbar bleiben, müssen aber ab dem Zeitpunkt, an dem sie Grundaufzeichnungs- oder Belegfunktion erfüllen, festgeschrieben oder versioniert werden. Dieser Übergang wird explizit modelliert.

### 5.3 Beweissicherer Dokumentenspeicher

Ein `EvidenceStorage`-Contract definiert mindestens:

- `putOnce`,
- `read`,
- `verify`,
- Retention/Legal Hold,
- Versioning/Object Lock Capability,
- Export des unveränderten Originals.

Referenzbetrieb:

- privater, S3-kompatibler oder lokaler Objektspeicher mit Versionierung und unveränderbarer Aufbewahrung,
- getrennte Schreib-, Lese- und Löschberechtigungen,
- keine öffentliche URL als Dauerzugriff,
- regelmäßige vollständige Hash-Verifikation,
- replizierte, verschlüsselte Backups und dokumentierte Restore-Tests.

Ein normales Dateisystem darf nur eingesetzt werden, wenn zusätzliche technische und organisatorische Maßnahmen dieselben Kontrollziele nachweisbar erfüllen.

### 5.4 Programm- und Konfigurationsidentität

Jede relevante Buchung bzw. jeder Export muss auf einen nachvollziehbaren Systemstand zurückgeführt werden können:

- Produkt- und Paketversionen,
- Commit- und Release-Tag,
- Composer-Lock-/SBOM-Hash,
- Datenbankschema-/Migrationsstand,
- aktives Länder-/Compliance-Profil,
- Tax-/Posting-Regelversionen,
- Rechnungsrenderer-, Template-, Logo-, AGB- und Sprachversion,
- relevante Konfiguration ohne Secrets,
- Exporter- und E-Rechnungsvalidatorversion.

Hierfür werden immutable `system_snapshots` bzw. Release-Manifeste gespeichert. Secrets werden nie in Snapshots aufgenommen.

### 5.5 Zeitgerechte Erfassung und Festschreibung

Getrennt speichern:

- `occurred_at`/Leistungsdatum,
- `document_date`,
- `received_at`,
- `captured_at`,
- `posted_at`,
- `locked_at`,
- `corrected_at`/`reversed_at`.

Das Deutschland-Profil erzeugt Kontrollhinweise, wenn unbare Vorgänge nicht zeitnah erfasst oder Buchungsperioden unangemessen lange offen bleiben. Warnungen dürfen nicht durch das Zurückdatieren technischer Timestamps verschwinden.

### 5.6 Prüfpfad und Datenzugriff

#### Z1 – unmittelbarer Nur-Lesezugriff

- eigene, zeitlich begrenzte Prüferrolle,
- nur freigegebene Legal Entity und Zeiträume,
- keine Bearbeitungs- oder Exportmanipulationsrechte,
- Filter, Sortierung, Suche und Verfolgung der Beziehungen,
- progressive und retrograde Ansicht,
- protokollierte Erteilung und Entziehung des Zugriffs,
- kein unkontrollierter Fernzugriff der Finanzverwaltung.

#### Z2 – mittelbarer Zugriff

- gespeicherte, versionierte Prüfberichte und Auswertungen,
- Journal, Kontenblätter, Belegjournal, Steuerübersichten, offene Posten, Bankabgleich, Sequenz- und Lückenberichte,
- Parameter und Ausführungszeitpunkt werden mit dem Ergebnis gespeichert,
- Ausgabe in maschinell auswertbarer Form.

#### Z3 – Datenüberlassung

Ein deterministisches Exportpaket enthält mindestens:

- Meta-, Stamm- und Bewegungsdaten,
- Journale und Journalzeilen,
- Konten und Kontenrollen,
- Dokumente, Dokumentzeilen und Snapshots,
- Steuer- und Posting-Regelversionen,
- Bankkonten, Importläufe, Source-Versionen und Reconciliations,
- offene Posten und Settlements,
- Audit- und Periodenereignisse,
- Originalbelege und E-Rechnungsdateien,
- interne und externe Verknüpfungen über stabile IDs,
- Datenwörterbuch, Code-/Enumlisten, Tabellen- und Feldbeschreibung,
- `index.xml` bzw. die für die gewählte Datenüberlassung notwendige Strukturbeschreibung,
- Manifest mit Dateigröße, Datensatzanzahl, Betragskontrollsummen und SHA-256,
- System-, Exporter- und Schemasnapshot,
- menschenlesbare README zur Prüfung und Verifikation.

Der Export ist **nicht** mit einem DATEV-Export gleichzusetzen. DATEV bleibt ein eigener Adapter.

### 5.7 E-Rechnung

Für Deutschland:

- EN-16931-konforme Formate unterstützen,
- ZUGFeRD und XRechnung getrennt testen,
- das empfangene Original bytegenau und unverändert speichern,
- Hash vor Parsing oder Normalisierung bilden,
- strukturierten Teil als führenden Inhalt behandeln,
- PDF-/Bildteil zusätzlich halten, wenn er abweichende oder zusätzliche steuerlich relevante Informationen enthält,
- Validator, Regelwerk und Version mit Validierungsreport speichern,
- Original, extrahierte Felder, Visualisierung und Benutzerkorrektur getrennt halten,
- Korrekturrechnung eindeutig auf das Original beziehen,
- Transport-/Empfangsnachweis und Anhänge soweit steuerlich relevant erhalten,
- Ausgabe aus einem unveränderlichen Rechnungssnapshot erzeugen.

Die Generierung muss gegen offizielle bzw. anerkannte Testfälle und Schematron-Regeln geprüft werden. Ein Parserfolg allein reicht nicht.

### 5.8 Retention und Legal Hold

Neue Kernobjekte:

- `retention_policy_versions`,
- `retention_assignments`,
- `legal_holds`,
- `disposal_runs`,
- `disposal_items`,
- `disposal_certificates`.

Löschen erfolgt niemals per Model-Cascade aus einer Filament-Resource. Ein separater, hoch privilegierter Disposal-Workflow prüft:

1. Klassifikation,
2. Fristbeginn,
3. längste einschlägige Frist,
4. Legal Hold,
5. offene Prüfung/Rechtsbehelf,
6. referenzierte Dokumentation und Schlüssel,
7. Vier-Augen-Freigabe,
8. unveränderlichen Lösch-/Anonymisierungsnachweis.

---

## 6. Umsetzungsplan pro Repository

### 6.1 `filament-accounting`

#### ACC-01 – Compliance-Scope und Control Matrix

**Priorität:** P0  
**Ergebnis:** Prüfbarer Scope statt allgemeiner GoBD-Behauptung.

- ADR „GoBD responsibility and trust boundaries“.
- Mapping jeder GoBD-Anforderung auf Codekontrolle, organisatorische Kontrolle, Test und Evidenz.
- Deutschland-Profil explizit von generischem internationalen Core trennen.
- TSE/Kasse und Anlagenverwaltung als nicht enthalten dokumentieren.
- Referenzstack für die erste externe Prüfung festlegen.
- Host-Responsibility-Contract maschinenlesbar und als Dokument.

**Abnahme:** Jede Kontrollanforderung besitzt Owner, Implementierungsort, Test, Evidenz und Restrestrisiko.

#### ACC-02 – Unveränderliches Audit-Ledger

**Priorität:** P0

- Sequenz und Hash-Kette pro Legal Entity.
- AuditEvent gegen Update/Delete schützen.
- Externen Kettenanker implementieren.
- Kanonisierung und Offline-Verifier bereitstellen.
- Actor, Impersonation, Release, Konfiguration, Request und Causation erfassen.
- Vollständigkeit aller kritischen Service-Aktionen prüfen.

**Abnahme:** Änderung, Löschung, Einfügung oder Umsortierung eines historischen Events wird sicher erkannt; ein unabhängiges CLI-Tool validiert die Kette.

#### ACC-03 – Immutable Records und Datenbank-Härtung

**Priorität:** P0

- Einheitliches Immutable-Concern für festgeschriebene Fachobjekte.
- Schutz für Journal, Journalzeilen, Dokumente, Dokumentzeilen, Attachments, Reconciliation/Splits, Settlements und Regelversionen.
- Append-only Perioden-Transitionen.
- Direkte Query-Builder- und SQL-Bypass-Risiken reduzieren.
- Referenzdatenbank mit getrennten Rollen und optionalen DB-Constraints/Triggern härten.
- Migrations-/Administrationszugriffe strikt vom normalen App-Betrieb trennen.

**Abnahme:** Service-, Model-, Bulk-Update-, Direkt-SQL- und konkurrierende Mutationstests zeigen entweder Blockierung oder sichere Erkennung.

#### ACC-04 – Originalbeleg und Evidence Storage

**Priorität:** P0

- `EvidenceStorage`-Contract und Capability-Check.
- Put-once-Objekte, Versioning/Object Lock und Hashprüfung.
- Originaldatei, strukturierter Teil, weitere steuerlich relevante Teile und Metadaten getrennt modellieren.
- Regelmäßige Integritätsprüfung und Alarmierung.
- Restore- und Exportpfad testen.

**Abnahme:** Dateiänderung, Austausch, fehlendes Objekt und Hashabweichung werden erkannt; Original ist unabhängig vom Produktivpfad wiederherstellbar.

#### ACC-05 – Rechnungs- und Stammdatensnapshots

**Priorität:** P0

- Vollständiger Rechnungssnapshot bei Ausgabe/Empfang.
- Historisierung von Aussteller, Empfänger, Adressen, USt-ID, Zahlungsbedingungen, Bankdaten, Texten, AGB, Layout-/Templateversion und Steuerversion.
- Ausgabeartefakt bzw. jederzeit identisches Mehrstück sicherstellen.
- Lücken- und Sequenzbericht für Rechnungsnummern.
- Korrektur-/Stornorechnungsworkflow vervollständigen.

**Abnahme:** Eine Rechnung lässt sich nach Stammdaten- und Templateänderungen inhaltlich identisch darstellen und eindeutig zu ihrer Korrektur verfolgen.

#### ACC-06 – E-Rechnung vollständig machen

**Priorität:** P0/P1

- ZUGFeRD und XRechnung importieren, validieren, visualisieren und erzeugen.
- Original-XML vor Verarbeitung sichern.
- Validierungsreport mit Validator-/Regelversion speichern.
- Pflichtangaben, Summen, Steuerfälle und Referenzen fachlich prüfen.
- Hybridabweichungen und steuerlich relevante Anhänge behandeln.
- Testkorpus mit gültigen und absichtlich fehlerhaften Fällen.

**Abnahme:** Bytegenaues Original, reproduzierbares Validierungsergebnis, vollständige Buchungsverknüpfung und GoBD-Export sind nachgewiesen.

#### ACC-07 – Bankquell-Versionen und Reconciliation-Evidenz

**Priorität:** P0/P1

- Jede Quelländerung als Version statt Überschreiben.
- Vorher-/Nachher-Hash, Quellzeit, Importzeit, Sync-Run und Mapperversion speichern.
- Pending→Booked, Storno und Bankkorrektur separat modellieren.
- Reconciliation/Split/Settlement append-only bzw. reversal-basiert schützen.
- Kontrollsummen: importiert, geändert, übersprungen, zugeordnet, offen.

**Abnahme:** Jeder zugeordnete Bankbetrag ist zur exakten empfangenen Quellversion und jede Änderung zur Bankquelle zurückverfolgbar.

#### ACC-08 – Zeitgerechtigkeit, Perioden und Festschreibung

**Priorität:** P1

- Zeitpunkte fachlich trennen.
- Erfassungs- und Buchungsfristen als Profile/Controls konfigurieren.
- Soft-/Hard-Close mit unveränderlicher Transition-Historie.
- Wiederöffnung nur mit Sonderrecht, Grund und optionalem Vier-Augen-Prinzip.
- Bereits gebuchte Daten bleiben trotz Wiederöffnung unveränderlich.
- Verspätungs- und offene-Periode-Berichte.

**Abnahme:** Ein Prüfer kann alle Schließungen, Wiederöffnungen, verspäteten Erfassungen und nachträglichen Buchungen chronologisch nachvollziehen.

#### ACC-09 – Vollständiger Z1-/Z2-/Z3-Prüfzugriff

**Priorität:** P0/P1

- Prüferrolle und read-only Audit Workspace.
- Progressive/retrograde Navigation.
- Versionierte Standardauswertungen.
- Deterministischer Z3-Export mit Datenwörterbuch, Originalen, Beziehungen, Manifest und Kontrollsummen.
- Exportlauf selbst auditieren und unveränderlich speichern.
- Große Datenmengen und Abbruch/Wiederaufnahme testen.

**Abnahme:** Eine unabhängige Person kann Stichproben in beide Richtungen prüfen und das Exportpaket ohne Produktwissen maschinell auswerten.

#### ACC-10 – Retention, Legal Hold und Disposal

**Priorität:** P1

- Versionierte Aufbewahrungsmatrix pro Land, Legal Entity und Datentyp.
- Fristbeginn korrekt berechnen.
- Legal-Hold-Workflow.
- Vorschau, Vier-Augen-Freigabe und protokollierte Löschung.
- Löschschlüssel und Referenzen nicht vor abhängigen Daten entfernen.
- Datenschutzanforderungen mit steuerlicher Aufbewahrung abgleichen.

**Abnahme:** Kein aufbewahrungspflichtiger oder gesperrter Datensatz kann gelöscht werden; nach Fristablauf existiert ein nachvollziehbarer Disposal-Nachweis.

#### ACC-11 – Verify-/Evidence-CLI erweitern

**Priorität:** P0

Prüfen:

- Journalbalance und Zeilenregeln,
- Sequenzlücken und Dubletten,
- Hash-Ketten und externe Anchor,
- Beleg-/Storage-Hashes,
- progressive/retrograde Beziehungen,
- Dokument- und Steuersummen,
- Reconciliation-/Split-/Settlement-Summen,
- Perioden- und Zeitlogik,
- unveränderliche Regelversionen,
- Tenant-Isolation,
- Retention/Legal Hold,
- Exportmanifest und Kontrollsummen.

Ausgaben:

- menschenlesbarer Bericht,
- maschinenlesbares JSON,
- Exitcodes nach Schweregrad,
- keine personenbezogenen Vollinhalte in Logs.

#### ACC-12 – IKS und Funktionstrennung

**Priorität:** P1

- Rollenmatrix für Erfassen, Prüfen, Buchen, Festschreiben, Wiederöffnen, Stornieren, Exportieren und Löschen.
- Kritische Kombinationen erkennen.
- Regelmäßige Rezertifizierung von Rechten.
- Vier-Augen-Workflow für Periodenwiederöffnung, privilegierte Korrektur, Legal Hold und Disposal.
- Kontrollausführungen mit Ergebnis und Verantwortlichem speichern.

**Abnahme:** Jede definierte Kontrolle besitzt Häufigkeit, Verantwortlichen, Beleg und Eskalation.

#### ACC-13 – Verfahrensdokumentation als versioniertes Produktartefakt

**Priorität:** P0/P1

Liefern:

1. allgemeine Beschreibung,
2. Anwenderdokumentation,
3. technische Systemdokumentation,
4. Betriebsdokumentation,
5. IKS-Beschreibung,
6. Datenmodell-/Feldkatalog,
7. Rollen- und Berechtigungskonzept,
8. Backup-/Restore- und Notfallkonzept,
9. Scan-/Belegimportanweisung,
10. Änderungs- und Freigabeverfahren,
11. Export- und Betriebsprüfungsanweisung,
12. Aufbewahrungs- und Löschkonzept.

Repo-Dokumentation und kundenspezifische Betriebsdokumentation werden getrennt, aber eindeutig versioniert und miteinander verknüpft.

---

### 6.2 `filament-fints`

#### FINTS-01 – Exakte und versionierte Bankquellen

**Priorität:** P0

- Fachliche Geldwege ohne Float.
- Eingehende Banktransaktion als unveränderliche Version speichern.
- Pending→Booked und Storno als Zustandsfolge, nicht als Verlust des Vorgängers.
- Bank-/Provider-Referenzen, Abrufzeit und Original-/Quellhash erhalten.
- Eindeutige, dokumentierte Deduplikations- und Occurrence-Regeln.

#### FINTS-02 – Import-Evidenz und Kontrollsummen

**Priorität:** P0/P1

- Sync-Run um Anforderungsbereich, Antwort-/Snapshot-Hash, Zeilenanzahl, Summen nach Währung/Richtung, Insert/Update/Skip und Abschlussstatus erweitern.
- Technisch mögliche Rohantwort oder kanonischen Quellsnapshot beweissicher archivieren; Zugangsdaten, PIN, TAN und Dialoggeheimnisse ausschließen.
- Abgebrochene und wiederaufgenommene SCA-/Sync-Läufe nachvollziehbar machen.
- Sync-Run nach Abschluss unveränderlich machen.

#### FINTS-03 – Aufbewahrungs- und Löschgrenze

**Priorität:** P1

- Klare Klassifikation: steuerrelevante Bankquelle vs. kurzlebiger SCA-/Credential-Zustand.
- Steuerrelevante Quellen erst löschen, wenn Accounting-Übernahme mit Hash und Kontrollsummen bestätigt und die einschlägige Aufbewahrung erfüllt ist.
- Credentials und abgelaufene SCA-Daten gerade nicht unnötig steuerlich archivieren.
- Connector-/Kontolöschung darf keine relevante, noch nicht beweissicher übernommene Quelle kaskadierend entfernen.

#### FINTS-04 – Audit, Verify und Betriebsnachweis

**Priorität:** P1

- Owner-bezogene unveränderliche Security-/Sync-Audit-Ereignisse.
- Prüfbefehl für Fingerprints, Versionen, Sync-Kontrollsummen und Übergaben.
- Dokumentierte Schlüsselrotation, Backup und Restore.
- Datenschutzgerechte Log-/Payload-Minimierung beibehalten.

---

### 6.3 `filament-accounting-fints`

#### BRIDGE-01 – Provenance Envelope

**Priorität:** P0

Jede Übergabe enthält:

- Source Package und Source UUID,
- Source-Version,
- Sync-Run UUID,
- Source-/Originalhash,
- Mappername und Mapperschemaversion,
- Transformationszeit,
- Ziel-Legal-Entity und Zielkonto,
- exakte Beträge/Währungen,
- Payloadhash vor und nach Transformation,
- Correlation-/Causation-ID.

#### BRIDGE-02 – Exactly-once-Wirkung und Outbox

**Priorität:** P0/P1

- Ereignis-/Outbox-Muster oder gleichwertigen nachweisbaren Übergabemechanismus verwenden.
- Wiederholung darf dieselbe Quellversion nicht doppelt übernehmen.
- Eine neue Quellversion muss als solche ankommen.
- Crash zwischen Quellspeicherung, Event und Accounting-Import darf keine stille Lücke erzeugen.
- Dead-letter- und Recovery-Prozess mit sichtbarem Status.

#### BRIDGE-03 – Übergabekontrolle

**Priorität:** P0/P1

- Kontrollsummen und Mengen zwischen FinTS und Accounting abstimmen.
- Fehlende, zusätzliche und abweichende Datensätze melden.
- Replay-/Rescan-Bericht erzeugen.
- Importbestätigung mit Accounting-IDs und Hash speichern.

#### BRIDGE-04 – Vertrags- und Integrationstests

**Priorität:** P0

- Kompatibilitätsmatrix der unterstützten Paketversionen.
- Contract Tests für jede Source-/Mapperversion.
- Multi-Tenant-, Retry-, Reihenfolge-, Pending→Booked-, Storno-, Connector-Delete- und Recovery-Szenarien.
- Keine Buchhaltungslogik in die Bridge ziehen.

---

## 7. Repository- und Release-Governance

Die drei Standardbranches sind am geprüften Stand nicht geschützt. Vor einer Softwareprüfung ist ein verbindlicher Change-Prozess erforderlich.

### Pflichtmaßnahmen

- Branch Protection bzw. Rulesets für `main`/`master`.
- Pull Requests statt Direktcommits.
- Mindestens ein unabhängiges Review für compliance-relevante Änderungen.
- Required CI: Tests, statische Analyse, Formatter, Dependency-/Security-Scan, Migrationstest, Architektur- und Compliance-Tests.
- CODEOWNERS für Ledger, E-Rechnung, Export, Audit, Retention, FinTS Security und Bridge.
- Signierte oder anderweitig verifizierbare Release-Tags.
- Reproduzierbares Release-Manifest mit Commit, Abhängigkeiten, SBOM, Migrationen, Konfigurationsschema und Artefakthashes.
- SemVer und dokumentierte Upgrade-/Rollback-Strategie.
- Keine rückwirkende Änderung eines veröffentlichten Release-Artefakts.
- Security- und Compliance-Fixes erhalten nachvollziehbare Advisories/Changelog-Einträge.
- Unterstützte PHP-, Laravel-, Filament-, Datenbank- und Storage-Versionen eindeutig ausweisen.

### Zertifizierungsrelevante Referenzkonfiguration

Eine Prüfung kann nicht glaubwürdig jede beliebige Kombination zertifizieren. Zuerst wird genau eine Referenzkonfiguration eingefroren:

- konkrete PHP-Version,
- konkrete Laravel-/Filament-Hauptversion,
- konkrete Datenbank und Version,
- Queue-/Scheduler-Konfiguration,
- Evidence Storage,
- Deutschland-Profil,
- genau definierte Paketversionen,
- aktivierte Module und ausgeschlossene Funktionen,
- Backup-, Restore-, Monitoring- und Zugriffsverfahren.

Weitere Kombinationen werden später über eine kontrollierte Kompatibilitätsmatrix aufgenommen.

---

## 8. Host-/SaaS-Betriebsanforderungen

Diese Punkte können nicht allein durch die drei Composer-Pakete erfüllt werden.

### 8.1 Zugriff und Funktionstrennung

- MFA für privilegierte Rollen.
- Personenbezogene Benutzerkonten; keine gemeinsamen Admin-Accounts.
- Least Privilege und regelmäßige Rechteprüfung.
- Trennung von Entwicklung, Betrieb, Datenbankadministration und Buchungsfreigabe, soweit organisatorisch möglich.
- Notfallzugriffe zeitlich begrenzt, begründet und vollständig protokolliert.

### 8.2 Backup und Wiederherstellung

- verschlüsselte, getrennte und mindestens eine immutable/offline Sicherung,
- Datenbank, Originalbelege, externe Audit-Anker, Schlüssel und Verfahrensdokumentation gemeinsam betrachtbar,
- definierte RPO/RTO,
- mindestens quartalsweiser technischer Restore-Test,
- mindestens jährlicher vollständiger fachlicher Wiederanlauftest,
- Restore-Bericht mit Stichprobe progressiv und retrograd.

### 8.3 Schlüssel und Zeit

- Schlüssel nicht nur in derselben Datenbank sichern.
- Rotationsverfahren mit Lesbarkeit alter Daten.
- NTP/Zeitsynchronisation und UTC-Speicherung; fachliche lokale Zeitzone zusätzlich dokumentieren.
- Zeitabweichungen überwachen.

### 8.4 Monitoring und Incident Management

Alarmieren bei:

- fehlgeschlagenem Audit-/Hash-Check,
- fehlendem oder veränderten Belegobjekt,
- Sequenzlücke,
- gescheitertem Import/Bridge-Replay,
- ungewöhnlicher privilegierter Aktion,
- Backup-/Restorefehler,
- abgelaufener oder unzulässiger Konfiguration.

Jeder Incident erhält Bewertung, Ursache, Auswirkung auf Buchführungsdaten, Maßnahmen und Abschlussnachweis.

### 8.5 Speicherort

- Betrieb und Aufbewahrung innerhalb Deutschlands/EU bevorzugen.
- EU-Speicherorte müssen vollständigen Datenzugriff erlauben.
- Drittstaatenbetrieb nur nach rechtlicher Prüfung und gegebenenfalls Bewilligung gemäß § 146 AO.
- Unterauftragsverarbeiter, Speicherorte und Datenflüsse dokumentieren.

---

## 9. Test- und Evidenzstrategie

### 9.1 Automatisierte Muss-Tests

1. Gebuchtes Journal und Zeilen können nicht unbemerkt geändert oder gelöscht werden.
2. Ausgegebene/empfangene Rechnung und Zeilen bleiben unverändert.
3. Jede Korrektur referenziert das Original.
4. Audit-Event-Manipulation wird erkannt.
5. Belegdatei-Manipulation und Verlust werden erkannt.
6. Sequenzlücken und Dubletten werden berichtet.
7. Soll/Haben, Steuer- und Dokumentsummen stimmen exakt.
8. Zeitpunkte können nicht durch fachliches Zurückdatieren verschleiert werden.
9. Periodensperre und Wiederöffnung hinterlassen eine vollständige Historie.
10. Pending→Booked und Storno behalten alle Quellzustände.
11. Bridge-Retry ist idempotent; Crash-Recovery erzeugt weder Lücke noch Duplikat.
12. FinTS- und Accounting-Kontrollsummen stimmen überein.
13. Direkte Zahlung, Teilzahlung, Sammelzahlung und Split sind vollständig prüfbar.
14. Tenant A kann weder Daten noch Hash-/Exportinformationen von Tenant B sehen.
15. ZUGFeRD-/XRechnung-Original bleibt bytegenau erhalten.
16. Ungültige E-Rechnung speichert Original und Validierungsfehler, wird aber nicht unkontrolliert gebucht.
17. Z3-Export ist bei gleichem Datenstand deterministisch und vollständig.
18. Exportbeziehungen sind progressiv und retrograd auflösbar.
19. Legal Hold verhindert Löschung.
20. Restore eines Referenzbackups besteht `verify` und fachliche Stichproben.

### 9.2 Datenbankmatrix

- SQLite bleibt für schnelle Unit-Tests zulässig, ist aber nicht automatisch Zertifizierungsplattform.
- Referenzdatenbank in CI mit realen Constraints, Locking und Transaktionen testen.
- Jede zusätzlich unterstützte Produktionsdatenbank erhält eigene Integritäts-, Concurrency-, Migration- und Restore-Tests.

### 9.3 Manipulationstests

Ein eigener Testjob verändert absichtlich:

- Audit-Payload,
- Journalzeile,
- Originalbeleg,
- Bankquellversion,
- Exportdatei,
- Sequenz,
- externe Verknüpfung.

Der Job muss zeigen, welche Manipulation technisch verhindert und welche nachträglich sicher erkannt wird.

### 9.4 Prüfdatensatz

Ein vollständig synthetischer, datenschutzfreier Referenzmandant enthält:

- Ausgangs- und Eingangsrechnungen,
- ZUGFeRD und XRechnung,
- Teilzahlung, Sammelzahlung, Split, Skonto/Gebühr, Überzahlung, Storno und Korrektur,
- Fremdwährung,
- Steuersatzwechsel und Reverse Charge,
- Periodenschluss und begründete Wiederöffnung,
- Pending→Booked und Bankstorno,
- Migration von einer älteren Produktversion,
- vollständigen Z1-/Z2-/Z3-Prüfpfad.

Dieser Datensatz wird bei jedem zertifizierungsrelevanten Release unverändert wiederverwendet.

---

## 10. Phasen, Abhängigkeiten und Gates

| Phase | Schwerpunkt | Haupt-Repositories | Gate |
| --- | --- | --- | --- |
| 0 | Prüfungsumfang, Referenzstack, Control Matrix, Prüfer-Workshop | alle + Host | Schriftlich freigegebener Scope und keine irreführende Zertifizierungsaussage |
| 1 | Audit-Kette, Immutable Records, Evidence Storage | Accounting | Manipulationstest und Offline-Verifikation bestanden |
| 2 | FinTS-Quellversionen und Bridge-Provenance | FinTS + Bridge + Accounting | Ende-zu-Ende-Kontrollsummen und Recovery bestanden |
| 3 | Rechnungs-/E-Rechnungssnapshots | Accounting | Referenzkorpus und Originalerhalt bestanden |
| 4 | Z1/Z2/Z3, Verify und Retention | Accounting + Host | Prüfer kann vollständige Stichprobe ohne Entwicklerhilfe durchführen |
| 5 | IKS, Release Governance, Betrieb und Verfahrensdokumentation | alle + Host | Internes Readiness Review ohne kritische Findings |
| 6 | Unabhängige Vorprüfung | gesamter Scope | Findings mit Owner, Frist und Nachtest |
| 7 | Remediation, Release Freeze und formale Prüfung | gesamter Scope | Prüfbericht/Softwarebescheinigung für exakt benannte Version und Scope |
| 8 | Laufender Compliance-Betrieb | alle | jährliche Kontrolle, Rechtsmonitoring und Re-Prüfung bei wesentlicher Änderung |

### Grobe Größenordnung

Für ein kleines erfahrenes Team ist realistisch mit ungefähr **24–36 Entwicklungswochen** zu rechnen, verteilt auf mehrere Personen und zuzüglich externer Prüfungs-/Remediationzeit. Eine erste belastbare Softwareprüfung ist eher ein mehrmonatiges Programm als ein einzelner PR.

Die größten Aufwandstreiber sind:

- beweissicherer Storage und Hash-/Anchor-Konzept,
- vollständiger Z3-Export,
- E-Rechnungsvalidierung,
- Bankquellhistorie über drei Pakete,
- Betriebs- und Verfahrensdokumentation,
- externe Vorprüfung und Findings.

---

## 11. Empfohlene PR- und Release-Reihenfolge

### Welle 1 – Fundament

1. Accounting: Scope/Control Matrix und Audit-Ledger-Schema.
2. Accounting: Immutable Fachobjekte und periodische Integritätsprüfung.
3. Accounting: Evidence Storage.
4. FinTS: Transaktionsversionen und Sync-Evidenz.
5. Bridge: Provenance Envelope und Versionscontracts.

### Welle 2 – Ende-zu-Ende

6. Bridge: Outbox/Retry/Replay und Kontrollsummen.
7. Accounting: Bankquellversionen und Reconciliation-Härtung.
8. Accounting: Rechnungs-/Renderer-/Stammdatensnapshots.
9. Accounting: E-Rechnung komplett.

### Welle 3 – Prüfung und Betrieb

10. Accounting: Z1/Z2/Z3 und Verify.
11. Accounting: Retention/Legal Hold/Disposal.
12. Host: Backup, Restore, Monitoring, Rollen und Notfallzugriff.
13. Alle: Branch Protection, Releases, SBOM und Change Management.
14. Alle: vollständige Verfahrensdokumentation.

### Welle 4 – Externe Prüfung

15. Readiness-Release taggen und einfrieren.
16. Vorprüfung nach vereinbartem Kriterienkatalog.
17. Findings beheben und Regression/Evidenz aktualisieren.
18. Formale Prüfung nach IDW PS 880.
19. Bei SaaS-Betrieb zusätzlich PS-951-Scope entscheiden und umsetzen.

Cross-Repo-Änderungen werden stets in getrennten PRs umgesetzt. Jeder PR nennt kompatible Versionen und Merge-/Release-Reihenfolge. Die Bridge wird erst veröffentlicht, wenn die zugehörigen Accounting- und FinTS-Versionen verfügbar sind.

---

## 12. Externe Prüfung

### 12.1 Prüfer früh einbinden

Nicht erst nach Fertigstellung einen Prüfer suchen. Nach Phase 0 sollte ein Wirtschaftsprüfer/IT-Prüfer mit Erfahrung in:

- GoBD,
- IDW PS 880,
- Laravel-/Web-/SaaS-Systemen,
- E-Rechnung,
- Datenzugriff und Archivierung

den Kontrollkatalog und Referenzstack kommentieren.

### 12.2 Prüfgegenstand exakt definieren

Der Auftrag nennt:

- genaue Paket- und Host-Versionen,
- aktivierte Module,
- Deutschland-Profil,
- Datenbank-/Storage-/Queue-Konfiguration,
- ausgeschlossene Funktionen wie Anlagenverwaltung und Kasse/TSE,
- Anwender- und Betreiberkontrollen,
- Zeitraum bzw. Release,
- Abhängigkeiten und Complementary User Entity Controls.

### 12.3 Prüfungsnachweise vorbereiten

- Architektur und Datenfluss,
- Control Matrix,
- Source-/Release-Manifest,
- CI- und Reviewnachweise,
- Testberichte,
- Manipulations- und Restore-Tests,
- Rollen-/IKS-Nachweise,
- Beispiel-Exporte,
- vollständige Verfahrensdokumentation,
- bekannte Einschränkungen und Risikobehandlung.

### 12.4 Re-Prüfung

Eine neue Prüfung bzw. Delta-Prüfung ist insbesondere zu bewerten bei:

- wesentlichem Ledger-Umbau,
- Änderung der Immutable-/Hash-/Storage-Architektur,
- neuem E-Rechnungsstandard,
- neuem Datenbank-/Storage-Stack,
- erheblichen Änderungen am Export,
- neuem Landprofil,
- Rechtsänderung,
- Sicherheitsvorfall mit Rechnungslegungsbezug.

---

## 13. Definition of Done für „prüfbereit“

Das Projekt ist erst dann für eine externe Softwareprüfung bereit, wenn alle folgenden Punkte erfüllt sind:

- [ ] Schriftlicher Prüfungsumfang und Referenzstack sind freigegeben.
- [ ] Keine unqualifizierte „GoBD-zertifiziert“-Aussage wird verwendet.
- [ ] Alle P0-Kontrollen aus Accounting, FinTS und Bridge sind umgesetzt.
- [ ] Audit-Evidenz ist append-only und extern verankert.
- [ ] Originalbelege und E-Rechnungen sind unverändert, verifizierbar und wiederherstellbar.
- [ ] Alle relevanten Bankquellstände bleiben nachvollziehbar.
- [ ] Buchungen und Korrekturen sind progressiv und retrograd prüfbar.
- [ ] Z1-, Z2- und Z3-Verfahren sind implementiert und getestet.
- [ ] Z3-Export enthält Daten, Dokumente, Strukturinformationen, Beziehungen und Kontrollsummen.
- [ ] Retention, Legal Hold und Disposal sind technisch und organisatorisch definiert.
- [ ] Rollen, Funktionstrennung und Kontrollausführung sind nachweisbar.
- [ ] Branches, Releases und Änderungen folgen dokumentiertem Change Management.
- [ ] Vollständige versionierte Verfahrensdokumentation entspricht dem Referenzbetrieb.
- [ ] Backup-/Restore- und Notfalltests sind erfolgreich.
- [ ] Manipulations-, Tenant-, Concurrency- und Migrationsprüfungen sind erfolgreich.
- [ ] Ein unabhängiges Readiness Review enthält keine offenen kritischen Findings.
- [ ] Zertifizierungsrelease ist unveränderlich getaggt und reproduzierbar dokumentiert.

---

## 14. Unmittelbar nächste Schritte

1. Diesen Masterplan mit Steuerberatung und einem potenziellen PS-880-Prüfer in einem 90-minütigen Scope-Workshop validieren.
2. Eine repository-übergreifende `GOBD_CONTROL_MATRIX.md` mit eindeutigen Control-IDs anlegen.
3. Eine Referenzinstallation festlegen; nicht alle Laravel-/Filament-/DB-Kombinationen gleichzeitig in den ersten Prüfungsumfang nehmen.
4. Als ersten technischen Epic **Audit-Ledger + Evidence Storage + Immutable Records** umsetzen.
5. Parallel die FinTS-Source-Versionierung und das Bridge-Provenance-Contract spezifizieren.
6. Branch Protection und Required Checks sofort aktivieren, damit alle folgenden Compliance-Änderungen bereits unter kontrolliertem Verfahren entstehen.
7. Erst nach Abschluss des P0-Fundaments Z3-Export und vollständige E-Rechnungsprüfung bauen.
8. Nach dem internen Readiness Gate einen unabhängigen Pre-Audit durchführen.

---

## 15. Bewusste Nicht-Ziele

- Keine Anlagenverwaltung.
- Keine Registrierkasse oder TSE im aktuellen Scope.
- Keine Behauptung, Open Source oder Hashing allein mache einen Betrieb GoBD-konform.
- Keine externe kostenpflichtige KI für Buchungsvorschläge.
- Keine Vermischung der Customer- und Supplier-Rollen.
- Kein DATEV-Export als Ersatz für den vollständigen GoBD-Datenzugriff.
- Keine in-place Korrektur historischer Buchungen.
- Keine pauschale Aufbewahrung aller Daten für immer.
