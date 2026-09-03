# Codex-CLI-Build-Prompt: Drei Pakete zu einem einfachen „Filament Accounting“ konsolidieren

**Stand der Analyse:** 2. September 2026  
**Zielrepository:** `fliix-cloud/filament-accounting`  
**Quellrepositories:** `fliix-cloud/filament-fints` und `fliix-cloud/filament-accounting-fints`

> Diesen Prompt vollständig in Codex CLI ausführen. Nicht nach einer reinen Analyse oder einem weiteren Plan stoppen. Die Konsolidierung implementieren, umfassend testen und als Pull Request bereitstellen. Bestehende Repositories nicht löschen oder archivieren und keine Pull Requests selbst mergen.

---

## 1. Aufgabe und verbindliche Entscheidung

Konsolidiere die drei derzeit getrennten Produkt-/Filament-Pakete

- `fliix-cloud/filament-accounting`,
- `fliix-cloud/filament-fints` und
- `fliix-cloud/filament-accounting-fints`

zu **einem einzigen, vom Anwender direkt installierten Filament-Paket** namens:

```text
fliix-cloud/filament-accounting
```

Das überlebende Repository ist `fliix-cloud/filament-accounting`.

Eine transitive, UI-freie Composer-Abhängigkeit auf den reinen FinTS-Protokollkern ist ausdrücklich zulässig und architektonisch erwünscht. „Ein Paket“ bedeutet hier: Der Anwender installiert und konfiguriert nur ein Filament-Produktpaket. Es bedeutet nicht, bewährte Low-Level-Protokollbibliotheken in das Produktrepository zu kopieren. `nemiah/phpFinTS` bleibt technische Herkunft und read-only Bezugsquelle für allgemeine Protokollupdates; produktiv verwendet wird der dauerhaft selbst gepflegte Fork.

Das Ergebnis soll kein unstrukturierter Monolith sein. Es soll ein **modularer Monolith innerhalb eines Pakets** werden:

- eine Installation,
- ein Service Provider,
- ein Filament Plugin,
- eine Konfigurationsdatei,
- eine Ownership-/Tenancy-Grenze,
- ein Bankkonto-Modell und eine Bankkonto-Resource,
- ein Umsatz-/Banktransaktionsmodell und eine Resource,
- ein durchgängiger Zuordnungsassistent,
- keine Bridge,
- keine öffentliche Bank-Feed-Driver-Registry,
- keine doppelte Synchronisierung derselben Fachzustände.

Die technische Buchungs-, Audit- und Steuerlogik bleibt intern korrekt, versioniert und prüfbar. Die Bedienoberfläche darf diese interne Komplexität normalen Anwendern aber nicht mehr zumuten.

### Aktuell geprüfte Ausgangsstände

Beginne vor jeder Änderung mit einem erneuten Fetch/Pull der Default-Branches und dokumentiere Abweichungen von diesen analysierten Ständen:

| Repository | Default-Branch | Analysierter Commit |
| --- | --- | --- |
| `fliix-cloud/filament-fints` | `master` | `c69df127625e2c89f8e583394842e615d5311b07` |
| `fliix-cloud/filament-accounting` | `main` | `1c7742a6949309710111afdc9d29e52e9afb54aa` |
| `fliix-cloud/filament-accounting-fints` | `main` | `d4de82e9440f24374053d10ab3085814e3d26187` |

Alle drei Repositories hatten zum Analysezeitpunkt keine offenen Pull Requests.

---

## 2. Warum diese Konsolidierung durchgeführt wird

Die bisherige Trennung war architektonisch sauber, erzeugt für dieses Produkt aber mehr Kosten als Nutzen:

1. `filament-fints` besitzt Bankkonto- und Umsatzmodelle sowie eigene Filament Resources.
2. `filament-accounting` besitzt erneut Bankkonto- und Umsatz-/Statement-Modelle sowie eigene Resources.
3. `filament-accounting-fints` kopiert und transformiert Daten zwischen beiden Welten.
4. Die Bridge benötigt Events, Listener, Queue Jobs, Owner Mapping, Driver Keys, Source-Link-Generatoren, Mandatssynchronisierung und eigene Installationslogik.
5. Im Host müssen drei Pakete, drei Konfigurationen und zwei Filament Plugins korrekt zusammengesetzt werden.
6. Der gewünschte Produktumfang verwendet Accounting und FinTS ohnehin gemeinsam.
7. Das Projekt befindet sich noch vor einer stabilen Version; jetzt ist der richtige Zeitpunkt für eine kontrollierte Vereinfachung.

Die Konsolidierung darf nicht nur die Repository-Anzahl reduzieren. Sie muss auch die Fach- und Bedienkomplexität reduzieren.

---

## 3. Produktziel und bewusst enger Scope

Baue ein möglichst einfaches, mein-büroartiges System für kleine Unternehmen und Selbstständige. Es soll Standards gut abbilden, nicht beliebige Sonderkonstruktionen konfigurierbar machen.

### Muss-Funktionen

- Kundenverwaltung
- Lieferantenverwaltung
- dieselbe reale Firma/Person darf unabhängig als Kunde und Lieferant geführt werden
- Artikel/Leistungen für Ausgangsrechnungen
- Ausgangsrechnungen für Empfänger in Deutschland, der EU und außerhalb der EU
- PDF-Rechnung
- strukturierte E-Rechnung und ZUGFeRD/PDF-Einbettung, soweit vom vorhandenen Code unterstützt
- Original- und E-Rechnungsdateien auf konfigurierbarem Laravel Storage, insbesondere S3-kompatibel
- Eingangsrechnungen mit Upload als erster Schritt
- automatische Vorbelegung aus XML- oder E-Rechnungs-PDF
- manuelle Erfassung bei normalem PDF
- manuell zu bestätigende Kostenkategorie bei jeder Eingangsrechnung
- 0 %, 7 % und 19 % müssen verwendbar sein
- zeitlich versionierte Standard- und ermäßigte Steuersätze
- FinTS Bankverbindungen, Konten, Salden und Umsätze
- SEPA-Überweisung
- SEPA-Lastschrift und Mandate
- ein integrierter Zuordnungsassistent direkt aus der Umsatzliste
- direkte Zuordnung, Teilzahlung und echte Mehrfach-Splittbuchung
- lokale, deterministische Zuordnungsvorschläge ohne kostenpflichtige KI
- revisionsfähige interne Buchungs-, Audit- und Beleglogik

### Initial unterstütztes Steuerprofil

Die erste fachlich zugesicherte Version ist **Deutschland-first**:

- Unternehmensstandort Deutschland,
- Rechnungen an DE-, EU- und Nicht-EU-Empfänger,
- deutsche Standardkonten und deutsche Steuersätze,
- internationale Währungen nur dort, wo der bestehende Ledger sie bereits sicher verarbeitet.

„Rechnung weltweit stellen“ darf nicht als vollständige Steuer-Compliance für alle Staaten vermarktet werden. Eine spätere Unterstützung von Unternehmen mit Sitz in anderen Ländern muss über eigene, getestete Produktprofile erfolgen und ist nicht Teil dieses Refactorings.

### Bewusste Nicht-Ziele

- keine Anlagenverwaltung
- keine Registrierkasse, TSE oder DSFinV-K
- keine Lohn- und Gehaltsabrechnung
- keine Konsolidierung mehrerer Konzerngesellschaften
- keine frei programmierbaren Steuerregeln
- keine frei editierbaren Soll-/Haben-Buchungsvorlagen im normalen UI
- keine Custom-Driver-Plattform für beliebige Bankanbieter in Version 0.1
- kein DATEV-Export als Ersatz für einen vollständigen GoBD-Datenzugriff
- keine externe oder kostenpflichtige KI
- kein Pixel-/Design-Klon von WISO Mein Büro und keine Übernahme fremder Assets
- keine Behauptung „GoBD-zertifiziert“

---

## 4. Zielarchitektur

### 4.1 Ein Paket, intern klar getrennte Module

Nutze mindestens folgende fachliche Modulgrenzen innerhalb von `filament-accounting`:

```text
src/
├── Audit/
├── Banking/
│   ├── FinTs/
│   ├── Models/
│   ├── Services/
│   └── Support/
├── Documents/
├── EInvoicing/
├── Invoicing/
├── Ledger/
├── Parties/
├── Reconciliation/
├── Tax/
├── Filament/
├── Ownership/
└── Support/
```

Die exakte Verschiebung vorhandener Klassen darf pragmatisch erfolgen. Vermeide einen gleichzeitigen, rein kosmetischen Komplettumbau aller Accounting-Namespaces, wenn er das Fehlerrisiko erhöht. Der neue FinTS-Laravel-Layer soll jedoch unter `FilamentAccounting\Banking\FinTs\...` liegen und nicht als zweites Plugin weiterleben.

### 4.2 FinTS-Protokollkern und Upstream-Strategie

Kopiere den umfangreichen `Fhp\*`-Protokollcode **nicht** in `filament-accounting`. Halte Produktintegration und Protokollbibliothek getrennt. Das verhindert eine schwer wartbare Quellkopie. Der eigene Fork wird dauerhaft gepflegt und kann ausgewählte Sicherheits-, Bugfix- und Protokollupdates von `nemiah/phpFinTS` übernehmen, ohne eigene Änderungen dorthin zurückschreiben zu müssen.

Der aktuelle Stand muss dabei ehrlich berücksichtigt werden:

- `fliix-cloud/filament-fints` ist ein echter GitHub-Fork von `nemiah/phpFinTS`;
- der Fork war beim Review 124 Commits voraus und 0 Commits zurück;
- entgegen der Aussage im bisherigen `UPSTREAM.md` wurden fünf `Fhp\*`-Dateien substanziell verändert;
- betroffen sind gebuchte/vorgemerkte MT940-/CAMT-Umsätze, CAMT.053 und robuste Status-/Datumsbehandlung sowie SEPA-Lastschrift-Autorisierung, Vorlaufzeit und Kompatibilität persistierter Actions;
- eine sofortige Umstellung auf die unveränderte Version `nemiah/php-fints:4.1.0` kann daher Funktionen verlieren und ist ohne Tests unzulässig.

Erstelle zuerst eine überprüfbare Delta-Dokumentation, zum Beispiel:

```text
docs/upstream/php-fints-delta.md
```

Sie enthält mindestens:

- Upstream-Repository und gemeinsamen Basis-Commit,
- alle veränderten `Fhp\*`-Dateien,
- fachliche Begründung jeder Abweichung,
- zugehörige Regressionstests,
- Kennzeichnung als dauerhaft lokaler Patch,
- Konflikt- und Testhinweise für spätere Upstream-Synchronisierungen.

Gruppiere die lokalen Protokolländerungen intern in kleine, eigenständig testbare Patchbereiche, vorzugsweise:

1. gebuchte und vorgemerkte MT940-/CAMT-Umsätze,
2. CAMT.053 sowie robuste Status-/Datumsbehandlung,
3. SEPA-Lastschrift-Autorisierung, Vorlaufzeit und persistierte Actions.

Erstelle **keine Pull Requests, Issues oder sonstigen Schreibvorgänge bei `nemiah/phpFinTS`**. Die Änderungen sind projektspezifisch und werden ausschließlich im eigenen Fork gepflegt. Vermische den Protokoll-Fork trotzdem nicht mit Laravel-, Filament- oder Accounting-Code.

#### Verbindlicher Zielzustand

`fliix-cloud/filament-accounting` hängt dauerhaft von `fliix-cloud/php-fints` ab. Im Accounting-Repository liegen ausschließlich Adapter und Produktlogik unter `FilamentAccounting\Banking\FinTs\...`; es gibt dort kein eigenes `Fhp\`-Autoload-Mapping.

Erhalte die GitHub-Fork-Beziehung als Herkunfts- und Vergleichsbeziehung. Verschlanke das bestehende Fork-Repository in einem separaten, reviewbaren PR zu einer **reinen, dauerhaft selbst gepflegten Protokollbibliothek**:

- Repository vorzugsweise in `fliix-cloud/php-fints` umbenennen; die GitHub-Fork-Beziehung zu `nemiah/phpFinTS` bleibt dabei erhalten,
- Composer-Paket eindeutig als `fliix-cloud/php-fints` versionieren und veröffentlichen,
- `Fhp\*`, zugehörige Tests, Lizenz und korrigierte Upstream-Dokumentation behalten,
- `FilamentFints\*`, Service Provider, Plugin, Resources, Pages, Migrationen und Laravel-/Filament-Konfiguration vollständig in das vereinheitlichte Accounting-Paket migrieren und anschließend aus der Protokollbibliothek entfernen,
- keine Laravel Package Discovery und keine Filament-Abhängigkeiten in der Protokollbibliothek,
- das Accounting-Paket darf diese Bibliothek transitiv benötigen, der Host installiert und registriert weiterhin nur `fliix-cloud/filament-accounting`.

Definiere für spätere Synchronisierungen einen kontrollierten Prozess:

1. neue Upstream-Commits nur lesend prüfen,
2. relevante Änderungen in einer eigenen Sync-Branch übernehmen,
3. lokale Patchbereiche und Regressionstests vollständig ausführen,
4. Konflikte fachlich lösen und im Delta-Dokument festhalten,
5. erst danach eine neue Version von `fliix-cloud/php-fints` veröffentlichen.

Es gibt keinen geplanten späteren Wechsel auf `nemiah/php-fints` und keine Abhängigkeit von einer externen Merge- oder Release-Entscheidung. Ein solcher Wechsel wäre eine neue, ausdrücklich zu treffende Architekturentscheidung.

Vendoring bzw. Kopieren des `Fhp\*`-Kerns in `filament-accounting` ist nur ein ausdrücklich dokumentierter Notfall-Fallback, falls eine veröffentlichbare Protokollabhängigkeit technisch unmöglich ist. In diesem Fall sind Herkunft, Lizenz, Patchset und ein reproduzierbarer Upstream-Sync-Prozess zwingend; diese Variante ist nicht der Standardweg.

### 4.3 Nur noch eine öffentliche Laravel-/Filament-Integration

Es darf nach Abschluss nur noch geben:

```php
FilamentAccounting\FilamentAccountingServiceProvider
FilamentAccounting\FilamentAccountingPlugin
```

Installation:

```bash
composer require fliix-cloud/filament-accounting
php artisan filament-accounting:install --migrate --country=DE
```

Panel-Registrierung:

```php
$panel->plugin(
    \FilamentAccounting\FilamentAccountingPlugin::make()
);
```

Folgendes darf nicht mehr notwendig sein:

- Registrierung eines separaten `FilamentFintsPlugin`
- Registrierung von `FintsBankFeedDriver`
- Konfiguration eines `driver_key`
- Bindung eines `LegalEntityOwnerMapper`
- Ausblenden einer doppelten FinTS Transaction Resource
- Installation eines Bridge-Pakets

### 4.4 Sinnvolle interne Interfaces behalten

„Keine Drivers“ bedeutet: keine öffentliche, vom Benutzer zu konfigurierende Bank-Feed-Driver-Architektur für den einzigen unterstützten FinTS-Weg.

Interfaces an echten I/O- und Sicherheitsgrenzen dürfen und sollen bleiben, insbesondere:

- FinTS Client/Factory für testbare Bankkommunikation,
- Storage-Abstraktionen,
- E-Rechnungs-Parser/Renderer,
- Actor-/Authorization-Grenzen,
- Audit-Anchor-Storage.

Entferne Abstraktionen nicht nur deshalb, weil sie Interfaces sind. Entferne sie, wenn sie ausschließlich durch die künstliche Pakettrennung benötigt werden.

---

## 5. Ownership, Tenancy und Security vereinheitlichen

Die Bridge benötigt heute eine Abbildung zwischen FinTS Owner und Accounting Legal Entity. Diese Doppelwelt entfällt.

### Ziel

- `LegalEntity` ist die fachliche Company-/Tenant-Grenze des gesamten Pakets.
- Bankverbindungen, Bankkonten, Umsätze, Überweisungen, Lastschriften, Mandate, Rechnungen und Journale gehören direkt und eindeutig zu einer `LegalEntity`.
- Nutze einen einzigen Resolver für die aktuelle Legal Entity.
- Nutze einen einzigen Actor Resolver für Berechtigung und Audit.
- Nutze einen einzigen Tenancy Context Activator für Queue Jobs.
- Untrusted Request-Parameter dürfen niemals die Legal Entity bestimmen.
- Bestehende Verschlüsselung von PIN, Benutzerkennung, Dialogzuständen und SCA-Daten bleibt erhalten.
- Queue Jobs transportieren nur skalare, dauerhafte Identität und aktivieren den Tenant-Kontext vor Model Queries.

### Zu entfernen oder zu ersetzen

- `FilamentAccountingFints\Contracts\LegalEntityOwnerMapper`
- `SameModelLegalEntityOwnerMapper`
- doppelte Bank-/Accounting-Tenancy-Aktivierung im selben Job
- Bridge-Events, die nur Account- oder Owner-Zustände spiegeln

Bei bestehenden polymorphen `owner_type`/`owner_id`-Spalten ist eine sichere Migration zu `legal_entity_id` zu implementieren. Keine Daten anhand von Namen oder IBAN erraten. Nicht eindeutig abbildbare Datensätze müssen im Dry Run als Blocker ausgegeben werden.

---

## 6. Bankkonto- und Umsatzmodell konsolidieren

### 6.1 Ein Bankkonto statt zwei synchronisierter Bankkonten

Aktuell existieren `FilamentFints\Models\BankAccount` und `FilamentAccounting\Models\AccountingBankAccount`. Das Ziel ist ein kanonisches Bankkonto-Modell mit einer Filament Resource.

Das kanonische Modell muss enthalten:

- Legal Entity
- Bankverbindung
- stabile UUID
- IBAN/BIC und Bank-/Kontometadaten
- Verfügbarkeit bei der Bank
- Aktivierung durch den Benutzer
- Salden und Synchronisationszeitpunkte
- interne Ledger-Verknüpfung

Die Ledger-Verknüpfung wird **automatisch** erzeugt. Ein normaler Anwender darf kein Sachkonto pro Bankkonto auswählen oder bestätigen müssen.

Implementiere einen internen `BankLedgerAccountProvisioner` oder gleichwertigen Service:

- erzeugt pro aktivem Bankkonto automatisch ein passendes Asset-/Bankkonto,
- vergibt deterministisch einen freien Code im vorgesehenen Bankkontenbereich,
- setzt die interne Zuordnung,
- protokolliert die Anlage,
- ist idempotent,
- zeigt im normalen UI weder Soll/Haben noch Kontenmapping an.

Entferne `ledger_mapping_confirmed` aus dem Benutzerworkflow. Falls das Feld aus Kompatibilitätsgründen vorübergehend bestehen bleibt, muss es intern automatisch gesetzt werden und darf nicht als Pflichtschalter angezeigt werden.

### 6.2 Ein Umsatzdatensatz und eine Umsatz-Resource

Aktuell existieren FinTS Bank Transactions und Accounting Bank Statement Lines parallel. Nach der Konsolidierung gibt es genau einen kanonischen, im UI sichtbaren Umsatzdatensatz.

Es ist zulässig, die bestehende Tabelle `accounting_bank_statement_lines` zunächst als physische Tabelle beizubehalten, um riskante Datenbankumbenennungen zu vermeiden. Das Fachmodell und die Resource sollen für Benutzer aber eindeutig „BankTransaction“ bzw. „Umsatz“ heißen.

Der Datensatz enthält:

- Bankkonto
- FinTS Source-ID/Fingerprint
- Betrag in Minor Units und Währung
- Soll/Haben bzw. fachlich verständliche Ein-/Ausgangsrichtung
- Buchungs- und Wertstellungsdatum
- Pending/Booked/Storno
- Gegenpartei und Gegenkonto/IBAN
- Verwendungszweck
- End-to-End- und Zahlungsreferenz
- Rohquellen-Hash
- Import- und Änderungszeitpunkte
- Zuordnungsstatus

Die FinTS-Synchronisierung schreibt künftig direkt über einen internen, idempotenten Importservice in diesen kanonischen Datensatz. Folgende Kette entfällt:

```text
FinTS Transaction
→ Event
→ Bridge Listener
→ Queue Job
→ FintsBankFeedDriver
→ DTO Mapper
→ Accounting Statement Line Copy
```

Ziel:

```text
FinTS Sync
→ UnifiedBankTransactionImporter
→ kanonischer Umsatz
→ Vorschläge/Zuordnung
```

### 6.3 Quellnachweis nicht verlieren

Die Beseitigung doppelter Fachdatensätze darf den GoBD-/Audit-Nachweis nicht schwächen.

Implementiere eine append-only Quellversionierung, z. B.:

```text
accounting_bank_transaction_source_versions
```

Sie speichert bei relevanten Bankänderungen:

- Umsatz-ID
- Source-ID/Fingerprint
- normalisierten Payload
- optional den verfügbaren Rohpayload
- Hash
- Source-Status
- Importlauf
- Erfassungszeitpunkt

Pending→Booked, Storno und nachträgliche Bankänderungen müssen nachvollziehbar bleiben. Eine neue Version darf die vorherige nicht überschreiben.

### 6.4 Bridge-Code entfernen

Folgende Konzepte werden nach erfolgreicher direkter Integration entfernt:

- `BankFeedDriver`
- `BankFeedDriverRegistry`
- `BankFeedRegistry`
- `FintsBankFeedDriver`
- `driver_key` als öffentliches Konfigurationskonzept
- `BankSourceLinkRegistry` und Bridge-Source-Link-Generator, sofern die direkte Resource-Verknüpfung dies ersetzt
- `FintsTransactionMapper` als Bridge-Klasse; fachlich nötige Normalisierung kommt in den direkten Importservice
- `ImportOnBankStatementLinesChanged`
- `SyncAccountingBankAccountUsage`
- Bridge-Install- und Sync-Kommandos
- Bridge-spezifische List-Page-Overrides

Falls ein internes Feld `source = fints` für Herkunft, Audit oder zukünftige Migrationen sinnvoll ist, darf es bleiben. Es darf jedoch keine dynamische Driver-Konfiguration im UI oder in der Standardinstallation erfordern.

---

## 7. SEPA-Lastschrift und Mandate vereinfachen

Heute werden Mandatsdaten zwischen `PartyBankAccount` und FinTS Mandate synchronisiert. Im einheitlichen Paket darf es nur eine fachliche Quelle geben.

### Zielmodell

- `PartyBankAccount` speichert das Bankkonto eines Kunden/Lieferanten.
- `DirectDebitMandate` ist die autoritative Mandatsentität und gehört eindeutig zu Legal Entity, Customer/Party und Party Bank Account.
- Ein Mandat enthält Referenz, Schema, Typ, Unterschriftsdatum, Status, Erstnutzung und Gläubigerbezug.
- Verwendete Mandatsidentität wird nicht in-place verändert; ein geändertes Mandat erzeugt einen Nachfolger.
- Die Gläubiger-ID und Standard-Gläubigerdaten werden in den Unternehmenseinstellungen gepflegt.
- Es gibt keine Listener-basierte Synchronisierung zwischen zwei Mandatskopien.
- Im Customer-Workflow kann ein Mandat verständlich angelegt und eingesehen werden.
- Die SEPA-Lastschrift wählt direkt dieses Mandat.

Übernimm bestehende Sicherheits- und Identitätsprüfungen aus `ProtectUsedFintsMandate` und den aktuellen FinTS Services, aber entferne die Synchronisierungsschicht.

---

## 8. Steuer- und Buchungslogik: intern vollständig, im UI einfach

Die derzeitige UI zeigt interne Mechanik direkt an. Das ist zu entfernen.

### 8.1 Nicht mehr im normalen Menü anzeigen

Normale Benutzer dürfen nicht verwalten müssen:

- Ledger Accounts mit Account Type und Normal Balance
- Kontenrahmen-Zuordnungen
- Posting-Rule-Versionen
- `account_mappings`
- `line_templates`
- technische Tax Codes
- `recoverable`, `export_mapping` oder Compliance-Profile-Keys
- Bankkonto-zu-Ledger-Zuordnungen
- Audit-Hash-Details

Diese Daten dürfen intern weiterhin existieren und für Auditoren/Support read-only verfügbar sein. Entferne Create/Edit/Delete für historische oder systemgeführte Konten und Regeln.

### 8.2 Ein opinionated Deutschland-Profil

Beim Setup einer deutschen Legal Entity werden automatisch angelegt:

- interner Standardkontenrahmen bzw. semantische Kontenrollen,
- Debitoren-/Kreditoren-/Vorsteuer-/Umsatzsteuerrollen,
- Standardbuchungsregeln,
- Standardkostenkategorien,
- aktuelle und historisch benötigte Steuersatzzeiträume.

Die Bedienung arbeitet mit verständlichen Kategorien. Interne Kontencodes und Buchungszeilen werden daraus deterministisch erzeugt.

### 8.3 Einfache Steuersatzverwaltung mit Zeiträumen

Ersetze die technische `TaxCodeResource` im normalen UI durch eine kleine Seite „Steuersätze“.

Sie zeigt im Regelfall nur:

| Typ | Satz | Gültig ab | Gültig bis |
| --- | ---: | --- | --- |
| Standard | 19 % | 01.01.2021 | offen |
| Ermäßigt | 7 % | 01.01.2021 | offen |

Historische deutsche Zeiträume, einschließlich 16 %/5 % im zweiten Halbjahr 2020, werden korrekt vorinstalliert, damit rückdatierte Belege auflösbar bleiben.

Regeln:

- keine überlappenden Zeiträume je Typ,
- eine verwendete historische Version wird nicht geändert oder gelöscht,
- Änderungen erfolgen durch neue Version mit `valid_from`,
- bei einer zukünftigen gesetzlichen Änderung kann ein Admin einen neuen Standard- oder ermäßigten Satz mit Beginn anlegen,
- 0 % ist eine steuerliche Behandlung, nicht einfach eine neue Version des normalen Standardsatzes,
- ein Beleg speichert immer den aufgelösten Satz und die steuerliche Behandlung als Snapshot.

### 8.4 Verständliche Steueroptionen

Zeige Anwendern nur klare Optionen, beispielsweise:

- Standardsteuersatz
- Ermäßigter Steuersatz
- 0 % / steuerfrei
- Reverse Charge
- Innergemeinschaftlicher Erwerb, nur wenn fachlich relevant

Die UI zeigt den zum Belegdatum aufgelösten Prozentsatz, nicht technische Codes wie `DE-19`.

Bei 0 % oder Sonderbehandlung muss ein verständlicher Grund bzw. die erforderliche Rechnungsangabe gespeichert werden. Das System darf steuerlich mehrdeutige internationale Fälle nicht stillschweigend raten.

### 8.5 Ausgangsrechnungen DE/EU/Welt

Behalte für den Benutzer eine einfache Rechnungserstellung:

- Kunde
- Rechnungs-/Leistungs-/Fälligkeitsdatum
- Position/Artikel
- Menge
- Preis
- verständliche Steueroption

Standardwert bei inländischen Positionen ist der beim Artikel hinterlegte Typ, andernfalls „Standardsteuersatz“.

Implementiere einen testbaren `SalesTaxSuggestionService` oder gleichwertigen Service, der anhand mindestens folgender Daten eine **erklärte Empfehlung** liefert:

- Unternehmensland,
- Kundenland,
- Geschäftskunde/Privatkunde,
- USt-IdNr., wenn vorhanden,
- Ware oder Dienstleistung,
- Beleg-/Leistungsdatum,
- Steuerklasse des Artikels.

Für typische DE-, EU-B2B- und Nicht-EU-Fälle darf das System vorbelegen. Es muss die Entscheidung in Klartext erklären und bei mehrdeutigen oder nicht unterstützten Fällen eine Bestätigung verlangen. Geografie allein reicht nicht für jede steuerliche Entscheidung.

### 8.6 Eingangsrechnungen

Der Workflow beginnt immer mit dem Upload:

1. PDF oder XML hochladen.
2. Strukturierte E-Rechnung erkennen und vorhandene Daten vorbefüllen.
3. Normales PDF als Original speichern und manuelle Erfassung anbieten.
4. Lieferant, Nummer, Datum, Beträge und Steuer prüfen.
5. Für jede relevante Position eine **Kostenkategorie manuell festlegen bzw. bestätigen**.
6. Rechnung registrieren/buchen.

Standardkostenkategorien sollen mindestens enthalten:

- Wareneinkauf
- Fremdleistungen
- Sonstige Betriebsausgaben
- Bürobedarf
- Software/IT
- Miete und Nebenkosten
- Telefon/Internet
- Reisekosten
- Versicherungen
- Bankgebühren
- Personalkosten
- ungeklärter Posten

Die Auswahl einer Kategorie bestimmt intern das Konto. Entferne die zusätzliche Pflichtauswahl eines Ledger Accounts aus dem Formular.

Steueroptionen:

- 19 % Vorsteuer
- 7 % Vorsteuer
- 0 % / steuerfrei, beispielsweise IHK-Gebühren
- Reverse Charge
- innergemeinschaftlicher Erwerb

Die E-Rechnung darf den Steuerwert vorbefüllen. Die Kostenkategorie bleibt eine bewusste Benutzerbestätigung. Ersetze technische Toggle-Felder wie `classification_confirmed` und `tax_confirmed` durch einen verständlichen Review-/Bestätigungsschritt, nicht durch mehrere fachfremde Checkboxen.

---

## 9. Filament UX und Navigation

### 9.1 Zielnavigation

Die normale Navigation soll höchstens folgende Bereiche zeigen:

```text
Accounting
├── Übersicht
├── Verkauf
│   ├── Ausgangsrechnungen
│   ├── Kunden
│   └── Artikel & Leistungen
├── Einkauf
│   ├── Eingangsrechnungen
│   └── Lieferanten
├── Banking
│   ├── Umsätze
│   ├── Überweisungen
│   └── Lastschriften
├── Auswertungen
│   └── Journal (read-only)
└── Einstellungen
    ├── Unternehmen & Rechnungen
    ├── Bankverbindungen
    └── Steuersätze
```

Keine leeren Menügruppen registrieren. Erweiterte Audit-/Prüferansichten dürfen berechtigungsabhängig oder direkt per URL/CLI erreichbar sein, aber nicht die alltägliche Navigation überfrachten.

### 9.2 Ein Setup-Assistent

Implementiere einen einfachen erstmaligen Setup-Workflow:

1. Unternehmensdaten
2. Land `DE`, Basiswährung und Geschäftsjahr
3. Rechnungsdaten, Logo, Nummernkreis, Zahlungsziel und Bankverbindung auf Rechnungen
4. Umsatzsteuer-Identifikation und Standardsteuersatz
5. optional FinTS Bank verbinden
6. Zusammenfassung

Das Setup seedet die internen Konten, Regeln und Steuersätze automatisch. Es darf keinen Compliance-Profile-Key, Ledger-Typ oder Posting-Rule-Editor abfragen.

---

## 10. Zuordnungsassistent

Der Assistent wird als großes responsives Filament Modal/Slide-over direkt aus der Umsatzliste geöffnet. Eine separate technische Reconciliation-Seite darf intern bestehen, ist aber nicht der primäre Benutzerweg.

### 10.1 Kopfbereich

Zeige kompakt:

- Betrag und Währung
- Buchungsdatum/Wertstellung
- Empfänger/Auftraggeber
- IBAN, sofern vorhanden
- Verwendungszweck
- Bankkonto
- Zuordnungsstatus

### 10.2 Klare Zuordnungsarten

Für einen eingehenden Umsatz:

- Ausgangsrechnung
- Einnahmen-/Steuerkategorie
- Umbuchung
- Splittbuchung

Für einen ausgehenden Umsatz:

- Eingangsrechnung
- Ausgaben-/Steuerkategorie
- Umbuchung
- Splittbuchung

Die fachlich falsche Rechnungsrichtung wird nicht als gleichwertige Standardoption angeboten. Falls Korrektur-/Sonderfälle später benötigt werden, gehören sie hinter „Weitere Optionen“, nicht in den Hauptworkflow.

### 10.3 Direkte Zuordnung

- Eine normale Zuordnung weist den Umsatz genau einem Ziel zu.
- Ist der Zahlungsbetrag kleiner als der offene Rechnungsbetrag, entsteht eine Teilzahlung.
- Ist der Betrag größer als der offene Betrag, darf der Rest nicht stillschweigend verschwinden; der Assistent bietet Splitt oder Restkategorie an.
- Ein einzelnes Ziel wird niemals als „Split“ bezeichnet.

### 10.4 Echte Splittbuchung

Splitt wird nur verwendet, wenn mindestens zwei Teilzuordnungen existieren, beispielsweise:

- ein Kunde bezahlt mehrere Ausgangsrechnungen mit einer Überweisung,
- eine Kartenzahlung enthält mehrere Kostenkategorien,
- ein Teil gehört zu einer Rechnung und ein Teil zu einer Kategorie.

Regeln:

- mindestens zwei Positionen,
- jede Position besitzt Typ, Ziel und Betrag,
- Summe muss exakt dem Umsatzbetrag entsprechen,
- „Rest verwenden“-Aktion,
- offene Beträge sichtbar,
- Währung muss passen,
- negative oder Null-Splits ablehnen,
- Concurrency Lock beim Finalisieren,
- idempotent,
- Storno/Reversal statt stiller Änderung einer finalisierten Zuordnung.

### 10.5 Lokale Vorschläge ohne KI-Kosten

Nutze eine deterministische, nachvollziehbare Scoring-Engine. Mindestens:

- exakte Rechnungsnummer im Verwendungszweck,
- End-to-End-Referenz,
- exakter Betrag,
- offener Betrag,
- IBAN-Match,
- normalisierter Name,
- Datumsnähe,
- bisher bestätigte Zuordnungen derselben Gegenpartei,
- wiederkehrender Verwendungszweck,
- bekannte Kategoriehistorie.

Jeder Vorschlag zeigt:

- Ziel,
- Score/Vertrauensstufe,
- verständliche Gründe,
- keine Blackbox.

Lernregeln werden lokal gespeichert. Sie dürfen erst nach Benutzerbestätigung entstehen und müssen editierbar/löschbar sein. In Version 0.1 keine vollautomatische Buchung aufgrund eines gelernten Musters; der Benutzer bestätigt den Vorschlag.

Optional soll eine begrenzte Kombinationssuche Vorschläge für Sammelzahlungen über mehrere offene Rechnungen liefern. Kandidatenzahl und Laufzeit begrenzen und nur exakte, erklärbare Betragskombinationen vorschlagen.

---

## 11. Datenmigration und Rückwärtskompatibilität

### 11.1 Keine destruktive Abkürzung

Auch wenn das Projekt pre-1.0 ist:

- keine bestehenden Tabellen blind droppen,
- keine Repositories löschen,
- keine produktiven Daten voraussetzen oder ignorieren,
- keine Owner-Zuordnung anhand unsicherer Heuristik,
- keine Audit-Historie umschreiben.

### 11.2 Migrationsweg

Implementiere einen dokumentierten Übergang für Installationen, die bereits alle drei Pakete verwendet haben.

Erstelle beispielsweise:

```bash
php artisan filament-accounting:consolidate-legacy --dry-run
php artisan filament-accounting:consolidate-legacy
php artisan filament-accounting:verify
```

Der Dry Run berichtet mindestens:

- gefundene Legacy-Tabellen,
- Legal Entities und Owner-Zuordnungen,
- Bankverbindungen,
- Bankkonten,
- Transaktionen/Umsätze,
- bestehende Reconciliations/Settlements,
- Mandate,
- Konflikte und nicht eindeutig abbildbare Datensätze,
- erwartete Zielanzahlen.

Die Ausführung:

- ist idempotent,
- verwendet stabile UUIDs/Source-IDs,
- erhält Verknüpfungen zu Reconciliations und Settlements,
- validiert vor und nach dem Cutover Counts, Beträge und Hashes,
- schreibt einen Audit-/Migrationsnachweis,
- markiert Legacy-Tabellen zunächst nur read-only bzw. unbenutzt,
- löscht sie nicht automatisch.

### 11.3 Frische Installation

Eine frische Installation darf nur die Migrationen des einen Pakets benötigen. Kopiere erforderliche FinTS-Migrationen in das Zielrepository und stelle sicher, dass deren Laravel-Migrationsnamen mit bereits ausgeführten Installationen kompatibel bleiben.

Erstelle Tests für:

- leere Neuinstallation,
- Upgrade einer Accounting-only-Installation,
- Upgrade einer bisherigen Drei-Paket-Installation,
- wiederholten Dry Run,
- wiederholte Ausführung ohne Duplikate,
- Abbruch bei uneindeutigem Owner Mapping.

---

## 12. Konfiguration vereinfachen

Es gibt am Ende nur noch `config/filament-accounting.php`.

Die veröffentlichte Standardkonfiguration soll kurz und verständlich sein. Sie darf notwendige technische Werte enthalten, aber keine künstlichen Erweiterungspunkte für nicht vorhandene Implementierungen.

Mindestens:

```php
return [
    'database' => [
        'connection' => env('ACCOUNTING_DB_CONNECTION'),
    ],

    'storage' => [
        'disk' => env('ACCOUNTING_DISK', 'local'),
    ],

    'company' => [
        'country' => env('ACCOUNTING_COUNTRY', 'DE'),
    ],

    'banking' => [
        'fints' => [
            'product_id' => env('FINTS_PRODUCT_ID'),
            'sync_use_queue' => env('FINTS_SYNC_USE_QUEUE', false),
            'queue' => env('FINTS_QUEUE', 'default'),
        ],
    ],

    'audit' => [
        // bestehende sichere Anchor-Konfiguration konsolidieren
    ],
];
```

Security-relevante FinTS-Werte wie HTTPS-only, Endpoint-Validierung, SCA TTL und Redaction bleiben mit sicheren Defaults erhalten. Sie müssen nicht alle als normale Filament-Einstellung sichtbar sein.

Entferne aus der Standardkonfiguration:

- `bank_feeds.drivers`
- Bridge `driver_key`
- Bridge Legal Entity Mapper
- doppelte Owner-/Actor-Konfigurationen
- öffentliche Feature-Schalter für nicht unterstützte Sonderarchitekturen

---

## 13. Composer, Provider, Commands und Assets

### Composer

Führe die Laravel-/Filament-Laufzeitabhängigkeiten beider Hauptpakete zusammen. Das Zielpaket benötigt zusätzlich zu Accounting insbesondere die FinTS-/SEPA-Extensions und `nemiah/php-sepa-xml`, soweit vom migrierten Adaptercode benötigt.

Für den FinTS-Protokollkern gilt folgende Priorität:

1. `fliix-cloud/php-fints:^<veröffentlichte Version>` als dauerhaft gepflegte reine Protokollbibliothek,
2. kein direktes Runtime-Requirement auf `nemiah/php-fints`,
3. kein Kopieren des Protokollkerns in das Accounting-Paket als Standardlösung.

Entferne Abhängigkeiten auf:

```text
fliix-cloud/filament-fints
fliix-cloud/filament-accounting-fints
```

Die Protokollabhängigkeit darf nicht auf einer alten Version von `fliix-cloud/filament-fints` beruhen, die noch Laravel Package Discovery, einen Service Provider, ein Filament Plugin oder eigene Resources mitbringt.

Halte `composer.json` stabil, sortiert und mit `composer validate --strict` gültig.

### Commands

Konsolidiere unter einem Präfix:

```text
filament-accounting:install
filament-accounting:sync-bank
filament-accounting:sync-institutes
filament-accounting:cleanup-sca
filament-accounting:consolidate-legacy
filament-accounting:verify
filament-accounting:audit-*
```

### Übersetzungen, Views und Routes

- konsolidiere unter Translation Namespace `filament-accounting`,
- entferne doppelte Begriffe,
- behalte Deutsch und Englisch,
- übernehme SCA Views und sichere Challenge Routes,
- passe alle Asset-/View-Pfade an,
- prüfe Filament v5 APIs.

---

## 14. GoBD- und Audit-Anforderungen beibehalten

Die Vereinfachung der UI darf keine Abkürzung bei der Nachprüfbarkeit sein.

Behalte bzw. erweitere:

- unveränderliche gebuchte Journale,
- Korrektur durch Storno/Gegenbuchung,
- Beleg-Snapshots,
- unveränderte Originaldateien mit SHA-256,
- versionierte Steuerentscheidung je Belegposition,
- Audit-Hashkette und externe Anchors,
- Zeitstempel und Actor/Legal Entity,
- Periodenabschluss,
- idempotente Importe und Buchungen,
- progressiven und retrograden Prüfpfad,
- Z1-/Z2-/Z3-Planung,
- Retention/Legal Hold.

Aktualisiere im Zielrepository:

- `docs/GOBD_COMPLIANCE_MASTER_PLAN.md`
- `docs/GOBD_CONTROL_MATRIX.md`
- Architekturdiagramme
- Installations- und Migrationsdokumentation

Ersetze darin die Drei-Paket-Verantwortungsgrenzen durch die neuen internen Modulgrenzen. Historische Aussagen nicht stillschweigend verfälschen; kennzeichne die Konsolidierung als Architekturentscheidung mit Datum und Version.

Erstelle eine ADR, beispielsweise:

```text
docs/adr/0003-unified-accounting-package.md
```

Sie dokumentiert Entscheidung, Alternativen, Konsequenzen, Migration und Rückkehrstrategie.

---

## 15. Tests und Qualitäts-Gates

Übernimm die relevanten Tests aus allen drei Repositories in die Teststruktur des Zielpakets. Lösche keinen Test nur, weil der alte Namespace nicht mehr existiert; passe ihn an den neuen fachlichen Weg an.

### Pflicht-Testgruppen

#### Architektur

- nur ein Service Provider und ein Filament Plugin werden vom Host benötigt
- keine Runtime-Abhängigkeit auf die beiden alten **Filament-/Bridge-Pakete**
- `fliix-cloud/php-fints` enthält ausschließlich den `Fhp\*`-Protokollkern und keine Laravel-/Filament-Integration
- keine Klassenreferenzen auf `FilamentAccountingFints\*`
- kein öffentlicher `BankFeedDriver`-/Registry-Weg
- FinTS-Protokollschicht kennt keine Filament Resources
- Accounting enthält keine kopierte zweite `Fhp\*`-Quellstruktur
- Filament Callbacks enthalten keine zentrale Fachlogik

#### Installation und Migration

- frische SQLite-Installation
- unterstützte MySQL/MariaDB- oder PostgreSQL-Matrix entsprechend dem Projektstandard
- Legacy Dry Run
- idempotente Legacy-Migration
- keine verlorenen Reconciliation-/Settlement-Beziehungen

#### Banking/FinTS

- Konten-, Salden- und Umsatzsync
- Pending→Booked
- Storno
- doppelte Imports
- Account unavailable/disabled
- SCA TAN, decoupled polling und Ablauf
- Ambiguous Payment wird nicht automatisch wiederholt
- Überweisung
- Lastschrift
- Mandatsidentität
- Tenant-/Legal-Entity-Isolation

#### Steuern und Rechnungen

- 19 %, 7 % und 0 %
- historisch 16 % und 5 % im richtigen Zeitraum
- keine überlappenden Rate-Zeiträume
- verwendete Rate-Version unveränderlich
- Eingangsrechnung mit normalem PDF
- XML/E-Rechnungsimport
- Kostenkategorie ist erforderlich
- interne Kontierung wird ohne Ledger-Auswahl erzeugt
- Ausgangsrechnung PDF und E-Rechnung
- DE-, EU-B2B- und Nicht-EU-Beispielfälle mit erklärter Empfehlung
- unklarer Steuerfall verlangt Bestätigung statt stiller Annahme

#### Reconciliation

- eingehender Umsatz zeigt Ausgangsrechnungen
- ausgehender Umsatz zeigt Eingangsrechnungen
- direkte Einzelzuordnung
- Teilzahlung
- Mehrzahlung erfordert Restbehandlung
- Split mit mindestens zwei Positionen
- Split-Summe exakt
- Sammelzahlung über mehrere Rechnungen
- Zuordnung auf Standardkategorie
- Umbuchung
- Vorschlagsgründe und lokales Lernen
- kein Auto-Posting allein aufgrund eines Lernmusters
- Finalisierung ist concurrency-safe und idempotent
- Reversal erhält Historie

#### Audit/GoBD

- Source-Versionen append-only
- Originalbeleg-Hash verifizierbar
- Audit-Kette verifizierbar
- manipulierte Daten werden erkannt
- Steuer- und Kontierungssnapshots bleiben nach Stammdatenänderung unverändert

### Auszuführende Gates

Mindestens:

```bash
composer validate --strict --no-check-publish
composer test
composer analyse
composer format
```

Führe zusätzlich alle vorhandenen package-spezifischen Suites aus. CI darf für normale Tests keine echte Bankverbindung, keine Zugangsdaten und keinen kostenpflichtigen externen Dienst benötigen.

---

## 16. Implementierungsreihenfolge

Arbeite in einer neuen Branch im Zielrepository:

```text
refactor/unified-filament-accounting
```

Die beiden Quellrepositories werden während der Primärimplementierung read-only verwendet.

### Commit 1 – Architektur und Quellübernahme

- ADR anlegen
- Protokoll-Deltas gegen `nemiah/phpFinTS` inventarisieren und dokumentieren
- Regressionstests für die fünf veränderten `Fhp\*`-Dateien sicherstellen
- bestehenden Fork in eine protokollreine Bibliothek `fliix-cloud/php-fints` überführen
- read-only Upstream-Sync-Prozess dokumentieren; keine externen PRs oder Issues erstellen
- FinTS-Laravel-Code in internes Banking-Modul übernehmen
- Composer-Abhängigkeiten zusammenführen
- Build zunächst mit möglichst wenig Verhaltensänderung grün bekommen

### Commit 2 – Eine Laravel-/Filament-Integration

- Provider, Plugin, Commands, Config, Views, Routes und Übersetzungen konsolidieren
- alte Plugin-Registrierung entfernen
- einen Installer schaffen

### Commit 3 – Ownership und direkte Bankintegration

- Legal Entity vereinheitlichen
- kanonisches Bankkonto und Umsatzmodell
- direkte FinTS-Importpipeline
- Quellversionierung
- Bridge-Funktionen ersetzen
- sichere Legacy-Migration

### Commit 4 – SEPA und Mandate

- eine Mandatsquelle
- direkte Beziehungen
- bisherige Schutzregeln übernehmen
- Transfer-/Direct-Debit-Flows auf vereinheitlichte Modelle umstellen

### Commit 5 – Einfache Steuer- und Rechnungsoberfläche

- Standardprofil automatisch seeden
- einfache Steuersatzzeiträume
- einfache Kostenkategorien
- Ledger-/Posting-/Tax-Technik aus Standardnavigation entfernen
- Rechnungsworkflows vereinfachen

### Commit 6 – Zuordnungsassistent

- modaler Umsatzworkflow
- Richtungstrennung
- direkte Zuordnung/Teilzahlung
- echter Split
- lokale Vorschläge und Erklärungen

### Commit 7 – Dokumentation, Migration und vollständige Tests

- alle Dokumente aktualisieren
- Upgrade Guide
- GoBD-Unterlagen aktualisieren
- E2E-/Architektur-/Migrationstests vervollständigen
- Changelog

Halte die Commits logisch und einzeln baubar. Wenn die Branch wegen der Quellübernahme groß wird, darf der finale PR groß sein; die Commitstruktur muss den Review dennoch ermöglichen.

---

## 17. Alte Repositories und Veröffentlichung

Erst wenn der Primär-PR im Zielrepository vollständig grün und reviewbar ist:

1. Erstelle in `filament-accounting-fints` einen separaten Deprecation-PR.
2. Erstelle in `filament-fints` einen separaten Transition-PR zur dauerhaften Verschlankung als reine Protokollbibliothek `fliix-cloud/php-fints`.
3. Erhalte die GitHub-Fork-Beziehung zu `nemiah/phpFinTS` dauerhaft als read-only Herkunfts- und Synchronisationsbeziehung.
4. Korrigiere das nachweislich veraltete `UPSTREAM.md`; behaupte nicht länger, es gebe keine Änderungen am `Fhp\*`-Kern.
5. Die READMEs erklären klar, dass sämtliche Laravel-/Filament-Funktionen ab Version 0.1 in `fliix-cloud/filament-accounting` enthalten sind.
6. Verlinke Migration Guide, Zielrepository, Delta-Dokumentation und Upstream-Sync-Anleitung.
7. Ändere im alten Filament-/Bridge-Code keine Runtime-Implementierung mehr außer kritischen Sicherheitsfixes.
8. Archiviere oder lösche die Repositories nicht automatisch.
9. Markiere Composer/Packagist-Pakete erst nach der tatsächlichen Veröffentlichung kontrolliert als abandoned/replaced.

Im Ziel-`composer.json` darf bei einer stabilen Veröffentlichung `replace` nur dann verwendet werden, wenn Composer-Semantik, Versionen und Migrationsweg getestet sind. Keine vorschnelle globale Replacement-Behauptung für inkompatible Altversionen.

---

## 18. Definition of Done

Die Aufgabe ist erst abgeschlossen, wenn alle folgenden Punkte erfüllt sind:

- [ ] Ein Host installiert nur `fliix-cloud/filament-accounting`.
- [ ] Ein Host registriert nur `FilamentAccountingPlugin`.
- [ ] Der `Fhp\*`-Protokollkern wurde nicht in das Accounting-Repository kopiert.
- [ ] Benötigte Abweichungen zu `nemiah/phpFinTS` sind mit Tests und dauerhaftem Wartungs-/Sync-Plan dokumentiert.
- [ ] `fliix-cloud/php-fints` ist eine reine Protokollbibliothek ohne Laravel-/Filament-Seiteneffekte.
- [ ] Es wurden keine PRs, Issues oder sonstigen Änderungen bei `nemiah/phpFinTS` erstellt.
- [ ] FinTS Konten, Salden, Umsätze, Überweisungen, Lastschriften und SCA funktionieren im selben Paket.
- [ ] Die Bridge wird zur Laufzeit nicht mehr benötigt.
- [ ] Es gibt nur eine sichtbare Bankkonto-Resource.
- [ ] Es gibt nur eine sichtbare Umsatz-Resource.
- [ ] FinTS schreibt direkt und idempotent in das kanonische Umsatzmodell.
- [ ] Bankquelländerungen bleiben versioniert nachvollziehbar.
- [ ] Ein Bankkonto erhält sein internes Ledgerkonto automatisch.
- [ ] Normale Benutzer sehen keine Ledger-, Soll/Haben-, Mapping- oder Posting-Template-Einstellungen.
- [ ] Standard- und ermäßigter Steuersatz sind zeitlich versionierbar.
- [ ] 0 % ist vollständig unterstützt.
- [ ] Eingangsrechnungen beginnen mit Upload und verlangen eine Kostenkategorie.
- [ ] Ausgangsrechnungen erzeugen die vorhandenen PDF-/E-Rechnungsartefakte.
- [ ] Der Zuordnungsassistent trennt Eingangs- und Ausgangsrechnungen nach Umsatzrichtung.
- [ ] Split bedeutet mindestens zwei Teilzuordnungen.
- [ ] Lokale Vorschläge sind erklärbar und kostenfrei.
- [ ] Customer und Supplier bleiben getrennte Workflows, können aber dieselbe reale Gegenpartei repräsentieren.
- [ ] Audit-, Beleg-, Steuer- und Journalhistorie bleiben prüfbar.
- [ ] Frische Installation und Legacy-Migration sind getestet.
- [ ] Alle Tests, statische Analyse und Formatierung sind grün.
- [ ] Dokumentation und GoBD-Control-Matrix beschreiben die neue Realität.
- [ ] Primär-PR im Zielrepository ist angelegt.
- [ ] Deprecation-PR für die Bridge und Transition-/Deprecation-PR für den bisherigen FinTS-Fork sind angelegt, aber nicht gemergt.

---

## 19. Arbeitsregeln für Codex CLI

1. Lies zuerst alle `AGENTS.md`-Dateien und repository-spezifischen Anweisungen vollständig.
2. Prüfe `git status`, Default-Branches, Remotes und offene PRs.
3. Aktualisiere alle drei Repositories auf den neuesten Stand, ohne fremde Änderungen zu überschreiben.
4. Verwende die beiden Quellrepositories zunächst ausschließlich lesend.
5. Erstelle keine neue vierte Repository-Struktur und kein Meta-Paket.
6. Implementiere im bestehenden `filament-accounting`-Repository.
7. Verwende keine destruktiven Git- oder Datenbankbefehle.
8. Bewahre vorhandene Security-, SCA-, Idempotenz-, Tenant- und Audit-Invarianten.
9. Entferne keine Komplexität nur aus der UI, wenn dadurch fachliche Daten verloren gehen; verschiebe notwendige Mechanik hinter klare Services und Defaults.
10. Füge keine spekulativen Erweiterungspunkte für hypothetische Sonderfälle hinzu.
11. Vermeide `class_exists()`-Magie und optionale Laufzeitkopplung.
12. Nutze konkrete Constructor Dependencies und Domain Services.
13. Verwende Minor Units/`brick/money`, niemals Float-Beträge.
14. Ergänze Tests zusammen mit jeder fachlichen Änderung.
15. Zeige keine Secrets, PINs, TANs, Dialogdaten oder unredigierte Bankantworten in Logs, Diffs oder PRs.
16. Stoppe nur bei einem echten fachlichen Blocker, einer fehlenden Berechtigung oder nicht sicher migrierbaren Daten. Berichte dann exakt, was entschieden werden muss.
17. Stoppe nicht nach dem ADR oder einer Analyse. Liefere lauffähigen Code und Pull Requests.

---

## 20. Erwarteter Abschlussbericht

Gib am Ende aus:

1. klare Zusammenfassung der neuen Architektur,
2. PR-Link des Primär-PRs,
3. Links des Bridge-Deprecation-PRs und des FinTS-Transition-PRs,
4. vollständige Liste der dauerhaft lokalen Protokoll-Patches,
5. verwendete Version von `fliix-cloud/php-fints` samt dokumentiertem Upstream-Sync-Prozess,
6. Liste der entfernten Bridge-/Driver-Komponenten,
7. Mapping alter zu neuer Models/Tabellen/Namespaces,
8. Migrationsanleitung inklusive Dry Run,
9. neue Installation und Panel-Registrierung,
10. sichtbare Navigation und Steuer-UX,
11. Test- und CI-Ergebnisse,
12. bekannte Restpunkte und bewusst nicht unterstützte Steuerfälle,
13. Bestätigung, dass keine Repositories gelöscht, archiviert oder PRs gemergt wurden.
