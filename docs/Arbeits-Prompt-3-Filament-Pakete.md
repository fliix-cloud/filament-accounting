# Arbeits-Prompt: Drei Filament-Pakete bis zum vollständigen E2E-Test bringen

## Auftrag

Arbeite morgen die drei öffentlichen GitHub-Repositories gemeinsam bis zu einem belastbaren, reproduzierbaren End-to-End-Teststand durch:

- `fliix-cloud/filament-fints` (`master`)
- `fliix-cloud/filament-accounting` (`main`)
- `fliix-cloud/filament-accounting-fints` (`main`)

Stand des Reviews: 2026-09-01; fachliche Ergänzung zum Eingangsrechnungs-Workflow: 2026-09-02. Zuletzt geprüfte Commits:

- `filament-fints`: `a8360efea709000bde0c9faf0f93f3d7b6201570`
- `filament-accounting`: `3d9806b4b9762a4618793911e65816d8e45ef95c`
- `filament-accounting-fints`: `08fc6c71fccdb4782743e6c689535e007f2d190b`

Prüfe zu Beginn erneut die aktuellen Heads, offenen PRs/Issues und CI-Runs. Arbeite nicht direkt auf den Default-Branches. Erstelle pro Repository einen klar benannten Arbeits-Branch und kleine, reviewbare PRs; dokumentiere repoübergreifende Abhängigkeiten und die notwendige Merge-Reihenfolge.

## Zielbild

Ein frischer Laravel-13-/Filament-5-Demostand soll folgende Strecke vollständig und nachvollziehbar abdecken:

1. Bankverbindung anlegen und Bankkonten synchronisieren.
2. Salden und Umsätze synchronisieren, inklusive Idempotenz sowie Pending-zu-Booked-Übergang.
3. SEPA-Überweisung inklusive SCA/TAN ausführen.
4. SEPA-Lastschrift mit Gläubigerprofil und gültigem Mandat ausführen.
5. Ausgangsrechnung aus Stammdaten und Artikeln erstellen, freigeben und buchen.
6. Ein reproduzierbares Rechnungs-PDF erzeugen, das E-Rechnungs-XML einbetten, PDF und XML separat privat auf S3 speichern und beide sicher herunterladen können.
7. Den Eingangsrechnungs-Workflow immer mit einem Upload von PDF oder XML beginnen: strukturierte E-Rechnungen automatisch als vorausgefüllten Entwurf anlegen, eine Standard-PDF anschließend vollständig manuell erfassen; Originaldateien privat auf S3 speichern und sicher anzeigen/herunterladen.
8. Eingehende und ausgehende Umsätze vollständig oder teilweise Rechnungen zuordnen; Split-Zuordnungen und direkte Buchungen auf zulässige Sach-/Steuerkonten ermöglichen.
9. Kunden, Lieferanten, Anschriften, Steuerdaten, Bankkonten, Lastschriftmandate und Artikel im Filament-UI pflegen.
10. Die gesamte Strecke automatisiert soweit möglich und anschließend mit einem kontrollierten Live-Bank-Smoke-Test prüfen.

### Verbindliche fachliche Regel für Eingangsrechnungen

Der Workflow beginnt ausnahmslos mit der Auswahl bzw. dem Upload der Originalrechnung, nicht mit einem leeren Rechnungsformular:

1. **Eigenständige E-Rechnungs-XML:** Unterstützte Formate wie XRechnung bzw. EN-16931-konformes CII/UBL erkennen, sicher parsen und validieren. Danach automatisch einen Eingangsrechnungs-Entwurf anlegen und Lieferant, Rechnungsnummer, Daten, Währung, Positionen, Netto, Steuer, Brutto sowie Zahlungsdaten soweit vorhanden vorausfüllen.
2. **Hybrid-E-Rechnung als PDF:** Bei ZUGFeRD-/Factur-X-PDF das eingebettete XML extrahieren und validieren. Danach ebenfalls automatisch einen vorausgefüllten Eingangsrechnungs-Entwurf anlegen. PDF und extrahiertes XML bleiben beide mit dem Entwurf verknüpft.
3. **Standard-PDF ohne verwertbare E-Rechnung:** Das PDF zuerst sicher speichern und anschließend einen leeren manuellen Eingangsrechnungs-Entwurf mit der bereits verknüpften Originaldatei öffnen. Sämtliche Rechnungsdaten werden vom Benutzer selbst eingetragen; OCR ist keine Voraussetzung dieses Arbeitspakets.

Automatische Erkennung bedeutet niemals automatische Freigabe oder Buchung. Vor dem Buchen muss jede Eingangsrechnung manuell klassifiziert und geprüft werden:

- Die **Aufwands-/Buchungskategorie** ist verpflichtend manuell auszuwählen, zum Beispiel Wareneingang, Fremdleistungen, sonstige betriebliche Aufwendungen oder Reisekosten. Sie steuert das zulässige Aufwands-/Bestandskonto.
- Die **steuerliche Behandlung** ist getrennt davon verpflichtend manuell zu bestätigen, zum Beispiel Vorsteuer 19 %, Vorsteuer 7 %, steuerfrei, Reverse Charge oder innergemeinschaftlicher Erwerb. Aus E-Rechnungen gelesene Steuersätze sind nur vorausgefüllte Vorschläge.
- Ein expliziter Steuersatz von **0,00 %** muss zulässig sein. Dabei fachlich unterscheiden zwischen regulär mit 0 % besteuert, steuerbefreit, nicht steuerbar/nicht umsatzsteuerbar, Reverse Charge und innergemeinschaftlichem Erwerb. Für Fälle wie IHK-Beiträge wird die passende Behandlung manuell ausgewählt und gegebenenfalls mit Befreiungsgrund bzw. Rechtsgrundlage gespeichert; das System darf nicht allein aus dem Zahlenwert 0 % auf den Rechtsgrund schließen.
- Die ausgewählte Kategorie darf einen zulässigen/default Tax Code vorschlagen, aber niemals einen abweichenden Wert aus der Originalrechnung still überschreiben. Widersprüche zwischen Rechnungsdaten, Kategorie und Tax Code blockieren die Buchung und müssen sichtbar aufgelöst werden.
- Eine Rechnung darf mehrere Kategorien und Steuersätze enthalten. Eine Default-Kategorie auf Rechnungsebene ist erlaubt, benötigt aber Positions-/Split-Overrides. Vor dem Buchen muss der vollständige Nettobetrag klassifiziert sein; kein Rest darf unkontiert bleiben.

### Zentrale und zeitabhängige Steuerregeln

- Steuercodes und ihre Steuersatz-Versionen müssen zentral im Accounting-Bereich über eine berechtigungsgeschützte Filament-Ressource gepflegt werden können, jeweils sauber auf die aktuelle `LegalEntity` begrenzt. Deutsche Standardprofile dürfen als zentrale Vorlagen ausgerollt werden; die Sach-/Steuerkontenzuordnung bleibt Legal-Entity-spezifisch.
- Ein stabiler `TaxCode` beschreibt die steuerliche Bedeutung und Richtung; zeitabhängige `TaxRuleVersion`-Datensätze enthalten mindestens `valid_from`, `valid_to`, exakten Satz inklusive 0 %, Behandlung/Kategorie, Vorsteuerabzugsfähigkeit, Befreiungsgrund und Export-Mapping.
- Für denselben Tax Code dürfen sich Gültigkeitszeiträume nicht überschneiden. `valid_to` ist einschließlich; offene Zukunftszeiträume sind erlaubt. Lücken müssen sichtbar sein und führen am Belegdatum zu einem Validierungsfehler statt zu einem stillen 0-%-Fallback.
- Die wirksame Version wird anhand des steuerlich maßgeblichen Leistungs-/Steuerdatums bestimmt; nur wenn dieses nicht vorhanden und fachlich zulässig ist, darf kontrolliert auf das Rechnungsdatum zurückgefallen werden.
- Bereits verwendete Versionen dürfen nicht rückwirkend verändert oder gelöscht werden. Korrekturen erfolgen über eine neue Version. Rechnungspositionen speichern Tax-Code-/Versionsreferenz sowie einen unveränderlichen Snapshot aus Satz, Behandlung, Grund und berechneten Beträgen.
- Historische deutsche Zeiträume müssen abbildbar und getestet sein, insbesondere der zeitlich begrenzte Wechsel 19 % → 16 % → 19 % und 7 % → 5 % → 7 % im Jahr 2020/2021. Die konkreten Periodengrenzen werden als Testfixtures dokumentiert; Auswahltests prüfen jeweils den Tag vor, am und nach einem Wechsel.

## Bereits vorhandener Stand

### `filament-fints`

Vorhanden und durch Unit-/Feature-Tests abgedeckt:

- Kontenabgleich über `AccountSyncService`
- Saldenabgleich über `BalanceSyncService`
- Umsatzabgleich über `TransactionSyncService`
- Fingerprint-Deduplizierung, Überlappungsfenster und Pending-zu-Booked-Identität
- CLI- und Filament-Aktionen für Synchronisation
- SEPA Credit Transfer mit `pain.001`, normal/instant, Idempotenz, SCA, TAN, decoupled/push und VoP
- SEPA Direct Debit mit `pain.008`, Gläubigerprofil, FRST/RCUR/OOFF/FNAL, Lead-Time-/Capability-Prüfungen, Idempotenz und SCA
- Capability-Erkennung aus BPD

Letzte CI war grün; ein PHPUnit-Job meldete 250 Tests und 2.555 Assertions. Es existiert absichtlich kein automatisierter Live-Bank-Test. Deshalb sind Überweisung und Lastschrift im Code weit fortgeschritten, aber noch nicht gegen die konkrete Bank-/TAN-Konfiguration freigegeben.

Live-Voraussetzungen:

- bei der Bank akzeptierte `FINTS_PRODUCT_ID`
- korrekter Bank-Endpunkt und Test-/Live-Zugang
- ausgewähltes TAN-Verfahren und gegebenenfalls TAN-Medium
- gepflegter `account_holder_name`
- laufender Queue Worker und Scheduler für decoupled SCA
- für Lastschrift: Default-Gläubigerprofil, gültiges Kundenmandat, unterstütztes Konto/Schema und zulässiges Fälligkeitsdatum

### `filament-accounting`

Vorhanden und überwiegend getestet:

- doppelte Buchführung mit Minor Units/Brick Money
- Ausgangs- und Eingangsrechnungs-Domainservices
- Journale und offene Posten
- direkte, partielle und gesplittete Umsatzzuordnung zu Rechnungen
- Reversal und deterministische Zuordnungsvorschläge
- Posting Rules zur Aufteilung in Netto- und Steuerkonten
- Filament-Ressourcen für Kunden, Lieferanten und Artikel
- generischer `StoreAttachment` für ein konfigurierbares privates Laravel-Disk
- `ZugferdEInvoiceAdapter` zum Erzeugen/Lesen von XML
- tenantbezogene `TaxCode`-/`TaxRuleVersion`-Modelle mit `valid_from`, `valid_to` und `rate_bp`; der deutsche Seeder enthält bereits 19 %, 7 %, explizite 0 % und Reverse Charge

Letzte CI war grün; ein PHPUnit-Job meldete 72 Tests und 427 Assertions. Die Service-Schicht ist deutlich weiter als die tatsächlichen Filament-Workflows.

### `filament-accounting-fints`

Vorhanden:

- Überführung von FinTS-Konten und -Umsätzen in Accounting-Bankkonten und Bankauszugszeilen
- Beibehaltung von Purpose, End-to-End-Referenz, Gegenpartei, Source Payload und Hash
- idempotenter Import und Pending-zu-Booked-Identität
- Queue-Job und manueller Sync
- Kontoauswahl mit kombiniertem Umsatz-/Saldo-Sync
- Synchronisation von Kundenbankkonto/Accounting-Mandat zu FinTS-Lastschriftmandat

Die letzte CI ist rot, weil der Bridge-Workflow wenige Sekunden vor dem Accounting-Commit lief, der `BankStatementLineResource::listPageUsing()` ergänzt hat. Dadurch brachen alle 20 Bridge-Tests bereits beim Bootstrap ab. Mit den heutigen Heads sollte der konkrete API-Fehler behoben sein, das muss aber sofort durch einen Re-Run bestätigt werden.

## Bestätigte Lücken und Risiken

### P0 – blockiert den ersten vollständigen Gesamttest

1. **Repoübergreifende CI ist nicht reproduzierbar.** Die Bridge checkt ungepinnt die jeweils aktuellen Default-Branches der beiden anderen Repositories aus. Dadurch entstehen Rennen wie im letzten roten Lauf. Außerdem enthält der Workflow noch einen veralteten PR-only-Schritt „Use Accounting source-link contract while its PR is pending“ und keinen Composer-Validate-Job.

2. **Ausgangsrechnungs-Artefakte fehlen praktisch vollständig.** Es gibt weder PDF-Renderer noch Rechnungslayout, PDF/A-3-/ZUGFeRD-Einbettung, Artefakterzeugung, Speicherung von PDF und separatem XML, Download-Aktionen oder Render-/Template-Versionierung. Der vorhandene ZUGFeRD-Adapter hat keine Call-Sites und keine Integrationsabdeckung.

3. **Der notwendige Upload-first-Import für Eingangsrechnungen fehlt.** Die Purchase-Invoice-Ressource hat kein `FileUpload`; `StoreAttachment` ist nicht angebunden. Es fehlen die automatische Anlage aus eigenständiger E-Rechnungs-XML und ZUGFeRD-/Factur-X-PDF, der manuelle Pfad für Standard-PDF, PDF/XML-Validierung, S3-Verknüpfung, privater Download sowie die verpflichtende manuelle Aufwands- und Steuerklassifizierung vor der Buchung.

4. **Der Rechnungs-Lifecycle im UI ist inkonsistent.** `CreateSalesInvoice` ruft aktuell sofort `IssueSalesInvoice` auf, während die Resource weiterhin Edit- und Issue-Aktionen für Drafts anbietet. Die Issue-Aktion erzeugt ein neues Dokument, statt einen vorhandenen Entwurf freizugeben. Nach der Freigabe editierbare Datums-/Währungsfelder kollidieren mit der Modell-Immutabilität. Die Kombination aus Repeater-Relationship und eigenem Create-Service ist nicht durch Filament-Livewire-Tests abgesichert.

5. **Rechnungs- und Stammdaten reichen für belastbare PDF/XML-Ausgabe nicht.** Bei `LegalEntity` fehlen unter anderem Ausstelleranschrift, Steuer-/USt-ID, Kontakt, Bank-/Zahlungsdaten sowie Logo/Layout/Template-/Zahlungsbedingungen. Kunden- und Lieferantenmasken exponieren vorhandene Adress-/Steuer-/Zahlungsdaten nicht vollständig. `Party::snapshot()` enthält keine Anschriften oder Zahlungsdaten.

6. **Geldbeträge werden in der UI als rohe Minor Units erfasst.** `unit_price_minor` und Artikelpreise können dadurch leicht um Faktor 100 falsch gespeichert werden. Benötigt werden lokalisierte Dezimalfelder mit serverseitiger, getesteter Konvertierung.

7. **Artikel sind nicht in den Rechnungseditor integriert.** Der Service akzeptiert `catalog_item_id`, aber die Rechnung bietet keine Artikelauswahl mit Snapshot/Autofill.

8. **Die vorhandene Steuerbasis ist noch nicht ausreichend bedien- und revisionssicher.** `TaxCode` und `TaxRuleVersion` unterstützen bereits Zeiträume und 0 %, aber es gibt keine vollständige zentrale Filament-Pflege und keine belegte Überschneidungs-/Immutabilitätsprüfung. Fehlender/unbekannter Tax Code bzw. fehlende Version wird aktuell still als 0 % behandelt. Gemischte Steuersätze werden in `PostDocument` zu einer Steuerzeile aggregiert und nur mit dem Tax Code der ersten Position versehen. Steuerzeilen müssen nach Regel/Code/Version gruppiert und korrekt kontiert werden. Bei Eingangsrechnungen müssen Aufwandskategorie und steuerliche Behandlung getrennt, vollständig und manuell bestätigt sein; importierte Werte dürfen nur Vorschläge sein. Explizite 0 % dürfen nicht mit „unbekannt“ oder allen steuerfreien Rechtsfällen gleichgesetzt werden.

9. **Mandanten-/Ledger-Invarianten sind nicht überall gesichert.** Der Ledger prüft die Balance, aber nicht an jeder Buchungsstrecke, ob Konten zur selben `LegalEntity` gehören und aktiv sind. Party-Dropdowns sind nicht sichtbar auf die aktuelle Legal Entity eingeschränkt. Domain-Checks reichen nicht gegen Datenlecks im UI.

10. **Mehrere Bankkonten laufen auf dasselbe Bank-Sachkonto.** Die Bridge ordnet importierte reale Bankkonten pauschal dem einzelnen Konto mit `AccountRole::Bank` zu. Vor Reconciliation muss je Bankkonto eine explizite, pflegbare und mandantensichere Ledger-Kontenzuordnung existieren.

11. **Mandats-Synchronisation hat Lifecycle-Lücken.** Ohne Default-Gläubigerprofil kehrt der Listener still zurück; ein später angelegtes Profil backfillt bestehende Mandate nicht. Beim Entfernen einer Mandatsreferenz bleibt das alte FinTS-Mandat aktiv und `external_mandate_id` kann veraltet bleiben. IBAN-/Mandatsänderungen nach Nutzung brauchen eine explizite Regel statt stiller Divergenz.

12. **Owner-Mapping und Queue-Konfiguration des realen Demos sind nicht belegt.** FinTS verwendet standardmäßig den angemeldeten User als Owner; der Bridge-Defaultmapper erwartet hingegen, dass dieser Owner selbst eine Accounting-`LegalEntity` ist. Der externe Herd-Demostand ist nicht Teil der Repositories. Automatischer Import erfordert außerdem einen laufenden Queue Worker oder bewusstes `sync`-Queueing.

13. **`StoreAttachment` braucht Produktionshärtung.** Zu prüfen/ergänzen sind Write-Erfolg, Legal-Entity-Grenze des Attachables, Dateiname/MIME/Größe/PDF-Signatur, private Sichtbarkeit, Idempotenz/Duplikate, Cleanup bei DB-/Objektspeicherfehlern sowie Hash-/Integritätsprüfung.

14. **Direkte Zuordnung auf Sach-/Steuerkonten ist im UI unvollständig.** Die Service-Schicht kann Ledger-Ziele verarbeiten; der Reconciliation Assistant bietet sie nicht vollständig an. Posting Rules haben keine vollständige Filament-Pflege für Versionen, Steuer- und Kontenzuordnungen.

### P1 – nach dem ersten Gesamttest

- Aktionen „Rechnung bezahlen“ bzw. „Rechnung einziehen“, die SEPA-Überweisung/Lastschrift aus Rechnung, Lieferant/Kunde, Betrag und Referenz vorbelegen
- Gutschriften, Korrekturen und Storno-Workflow
- Reports sowie echte DATEV-/GoBD-Z3-Exporte statt nur generischem CSV
- vollständige GoBD-Betriebs-/Archivierungsprüfung
- stabile Versionen/Tags und dokumentierte Kompatibilitätsmatrix statt ausschließlich Dev-Branches

## Verbindliche Arbeitsreihenfolge

### 1. Baseline und CI stabilisieren

- Aktuelle Heads und Composer-Kompatibilität aller drei Pakete prüfen.
- Die Bridge-CI mit den aktuellen Heads erneut ausführen und den erwarteten grünen Zustand belegen.
- Die Bridge-CI reproduzierbar machen: kompatible SHAs/Tags oder eine im Repository gepflegte Compatibility-Matrix verwenden. Optional zusätzlich einen nicht blockierenden „latest heads“-Job behalten.
- Veralteten Source-Link-Workaround entfernen.
- `composer validate --strict`, PHPUnit für PHP 8.3/8.4/8.5, Pint und PHPStan in allen drei Repositories einheitlich sicherstellen.
- Keine roten oder übersprungenen Pflichtjobs akzeptieren.

### 2. Accounting-Invarianten vor UI-Ausbau korrigieren

- Unbekannte/fehlende Tax Codes und Versionen als Validierungsfehler behandeln.
- Eine zentrale, tenant-sichere Filament-Verwaltung für Tax Codes und zeitabhängige Tax Rule Versions ergänzen. 0 % als gültigen Satz erlauben und steuerliche Behandlung/Befreiungsgrund separat speichern.
- Überschneidungsfreie Gültigkeitszeiträume, unveränderliche bereits verwendete Versionen und Auswahl nach Leistungs-/Steuerdatum erzwingen. Historische 19/16/19- und 7/5/7-Wechsel mit Grenztagen testen.
- Steuerbuchungen je Tax Rule/Code/Version/Rate gruppieren; Tests mit mindestens 0 %, 7 % und 19 % sowie gemischten Sätzen in derselben Rechnung ergänzen.
- Bei sämtlichen Buchungen aktive Konten derselben Legal Entity erzwingen.
- Customer-/Supplier-Options und alle relevanten Queries tenant-sicher scopen.
- Pro importiertem Bankkonto eine explizite Ledger-Kontenzuordnung modellieren, migrieren, im UI pflegen und vor Reconciliation verlangen.

### 3. Stammdaten, Geldfelder und Rechnungs-Lifecycle vervollständigen

- Legal-Entity-Rechnungsprofil für vollständige Aussteller-, Steuer-, Kontakt-, Zahlungs- und Layoutdaten ergänzen.
- Adressen, Steuer-IDs, Zahlungsbedingungen, Default-Währung und Bankkonten in Customer/Supplier-Ressourcen pflegbar machen.
- Party-Snapshot um alle für ein unveränderliches Rechnungsdokument erforderlichen Daten erweitern.
- Lokalisierte Major-Unit-Eingabe für Artikel- und Rechnungsbeträge implementieren; intern weiterhin ausschließlich exakte Minor Units verwenden.
- Artikelauswahl mit serverseitigem Autofill und unveränderlichem Line Snapshot ergänzen.
- Einen eindeutigen Lifecycle umsetzen: Entwurf erstellen/bearbeiten → validieren → einmalig freigeben/buchen → unveränderlich. Bestehendes Dokument freigeben, kein zweites Dokument erzeugen.
- Filament-Livewire-Tests für Create, Edit, Issue, Validation, Tenant-Scoping und unveränderliche Issued Documents ergänzen.

### 4. Ausgangsrechnung als PDF + E-Rechnung + S3 implementieren

- Verbindliche Technologiegrenze: Runtime, CLI, Queue, Tests, CI und Pflicht-Abnahme bleiben ausschließlich PHP-basiert. Keine JVM, keine JAR-Ausführung und keine Java-basierten KoSIT-/veraPDF-Wrapper. XML-Schema- und Geschäftsregelprüfungen erfolgen mit PHP XML-Funktionen und versionierten lokalen Regelartefakten; PDF/A wird strukturell in PHP geprüft, ohne eine unabhängige Zertifizierung zu behaupten.
- Einen austauschbaren Invoice-Renderer und versioniertes Rechnungslayout einführen.
- Aus dem eingefrorenen Rechnungs-Snapshot ZUGFeRD-/Factur-X-XML im passenden EN-16931-Profil erzeugen und validieren.
- Ein visuell korrektes Rechnungs-PDF erzeugen und das XML standardskonform als eingebettete Datei in ein PDF/A-3-Dokument integrieren.
- PDF und identisches XML separat über das konfigurierte private Accounting-Disk speichern; mit `ACCOUNTING_DISK=s3` muss dies ohne Sonderpfad funktionieren.
- Beide Artefakte als Attachments mit MIME, Größe, Hash, Erzeugungszeit, Template-/Renderer-Version und Beziehung zum Dokument speichern.
- Sichere, autorisierte Download-/View-Aktionen mit temporären URLs oder gestreamtem Zugriff ergänzen; keine öffentliche S3-ACL.
- Wiederholtes Erzeugen muss idempotent sein oder explizit versionierte Artefakte anlegen.
- Tests: XML-Schema/Business Rules, eingebettetes XML byte-identisch zum separaten XML, PDF-Signatur, PDF/A-3-XMP-Marker, Output Intent, Associated-File-Beziehung und Attachment-Metadaten, Storage-Fakes, S3-kompatible Integration und visuelle Snapshot-/Renderprüfung. Alle Pflichtprüfungen laufen PHP-nativ; Java-basierte Validatoren sind ausgeschlossen.

### 5. Eingangsrechnungs-Import als Upload-first-Wizard implementieren

- Die bisherige direkte Create-Maske durch einen eindeutigen Wizard ersetzen: **Upload → Erkennen/Validieren → Entwurf prüfen/vervollständigen → Aufwands- und Steuerklassifizierung → Speichern/Buchen**.
- Im ersten Schritt PDF und XML akzeptieren. Serverseitig Dateigröße, Extension, MIME, PDF-Signatur bzw. wohlgeformtes XML prüfen; XML-Parser gegen XXE und externe Ressourcen absichern.
- Eigenständige XRechnung bzw. unterstütztes EN-16931-CII/UBL erkennen, validieren und daraus automatisch genau einen Eingangsrechnungs-Entwurf mit Positionen und Summen anlegen.
- Bei PDF zuerst nach eingebettetem ZUGFeRD-/Factur-X-XML suchen. Ist es gültig, ebenfalls automatisch einen vorausgefüllten Entwurf anlegen und PDF plus extrahiertes XML speichern.
- Ist eine PDF keine verwertbare E-Rechnung, einen leeren manuellen Entwurf mit bereits verknüpfter PDF öffnen. Keine Rechnungsdaten erraten; der Benutzer füllt sie vollständig selbst aus.
- Original-PDF bzw. Original-XML privat und mandantensicher über `StoreAttachment` auf dem Accounting-Disk speichern. Extrahiertes XML zusätzlich als eigenes, verknüpftes Attachment speichern. Upload und Entwurfsanlage atomar bzw. kompensierbar gestalten.
- Lieferanten-Matching nachvollziehbar vorschlagen, aber eine mehrdeutige Zuordnung oder Neuanlage immer bestätigen lassen.
- Duplikate vor Anlage anhand von Legal Entity, Lieferant, Rechnungsnummer, Rechnungsdatum, Betrag und Datei-Hash erkennen. Einen Retry idempotent behandeln.
- Aufwands-/Buchungskategorie für jede Rechnung verpflichtend manuell erfassen. Einen Invoice-Default mit Positions-/Split-Overrides anbieten, damit Wareneingang, sonstige betriebliche Aufwendungen usw. auf die richtigen Konten abgebildet werden.
- Steuerliche Behandlung pro Position bzw. Kontierungssplit getrennt verpflichtend bestätigen. 0 % ist explizit zulässig, verlangt aber die Auswahl der tatsächlichen Behandlung und gegebenenfalls eines Befreiungsgrunds. Importierte E-Rechnungs-Steuersätze und Kategorie-Defaults nur vorausfüllen; nie automatisch freigeben. Mismatch, fehlende Klassifizierung, fehlender Grund oder nicht vollständig zugeteilter Nettobetrag blockieren die Buchung.
- Gemischte Kategorien und Steuersätze korrekt gruppieren und buchen. Die Summe aller Kontierungssplits muss exakt Netto, Steuer und Brutto der Rechnung ergeben.
- Fehlerfälle zwischen Storage und DB ohne verwaiste Objekte/Datensätze behandeln und einen autorisierten View-/Download anbieten.
- Tests für eigenständige E-Rechnungs-XML, Hybrid-PDF, Standard-PDF, ungültige/gefährliche XML, ungültige PDF, Tenant-Grenze, Supplier-Matching, Duplikat/Retry, Storage-Fehler, Download-Autorisierung, manuelle Pflichtklassifizierung, Mixed-Tax/-Category und Posting-Blocker ergänzen.

### 6. Mandats- und Owner-Bridge schließen

- Einen expliziten Owner-Mapper für die reale Tenant-/Legal-Entity-Konfiguration bereitstellen oder die Demo eindeutig auf `SameModelLegalEntityOwnerMapper` konfigurieren; automatisiert testen.
- Backfill-/Sync-Command bzw. Job für vorhandene Kundenmandate ergänzen, wenn ein Gläubigerprofil später angelegt wird.
- Fehlendes Gläubigerprofil im UI/Log sichtbar und handlungsorientiert melden, statt still zurückzukehren.
- Entfernte/widerrufene Mandatsreferenzen korrekt in FinTS schließen/widerrufen und externe IDs bereinigen.
- Nach bereits erfolgter Nutzung unveränderliche Mandatsdaten schützen; bei neuer IBAN/Referenz ein neues Mandat verlangen.
- Queue-/Retry-/Idempotenz-Verhalten testen und die notwendige Worker-/Scheduler-Konfiguration dokumentieren.

### 7. Reconciliation vervollständigen

- Direkte, tenant-sichere Auswahl zulässiger Sach-/Steuerkonten im Reconciliation Assistant ergänzen.
- Posting-Rule-Versionen und Konten-/Steuerzuordnungen pflegbar machen oder für den ersten Test bewusst, sichtbar und validiert seeden.
- Tests für Eingang → Ausgangsrechnung, Ausgang → Eingangsrechnung, Teilzahlung, Split, direkte Kontierung, Mixed-Tax, Reversal, Duplicate Event und mehrere Bankkonten ergänzen.

### 8. Gemeinsamen Demo-/Integrationstest aufbauen

- Einen reproduzierbaren Demo-/Workbench-Aufbau versionieren. Der bisher erwähnte externe Herd-Demostand allein reicht nicht als prüfbare Quelle.
- Alle drei lokalen Packages und deren Provider gemeinsam laden.
- S3-kompatiblen Test-Storage (oder LocalStack/MinIO) für automatisierte Tests nutzen; echte Zugangsdaten nie committen.
- Queue Worker/Scheduler und Owner-/Legal-Entity-Mapping explizit konfigurieren.
- Eine einzige Setup-Dokumentation mit Migrationen, Seedern, `.env.example`, Testdaten und Startkommandos liefern.
- Automatisierte End-to-End-Tests für alle Schritte ohne echten Bankzugang ausführen. Den FinTS-Client hierfür faken, aber Event-, Queue-, Mapping-, Accounting-, Dokument- und Storage-Grenzen real durchlaufen lassen.

## Abnahmematrix

| Strecke | Automatisierte Abnahme | Manuelle Abnahme |
|---|---|---|
| Account Sync | Upsert, Dedupe, Owner/Entity, mehrere Konten | Konto erscheint mit korrekter IBAN/Inhaber |
| Balance Sync | Status/Saldo/Zeitpunkt, Retry | Banksaldo und Zeitpunkt plausibel |
| Transaction Sync | Initial/Overlap, Duplicate, pending→booked | Neue Umsätze einmalig sichtbar |
| SEPA Transfer | pain.001, Capability, Idempotenz, SCA-States | kleiner autorisierter Testbetrag, Bankstatus dokumentiert |
| SEPA Direct Debit | pain.008, Mandat, Sequence, Lead Time, SCA | autorisiertes Testmandat, Bankstatus dokumentiert |
| Ausgangsrechnung | Lifecycle, Buchung, Mixed Tax, PDF/XML/S3 | Layout geprüft; XML separat und im PDF; privater Download |
| Eingangsrechnung | XML/Hybrid-PDF automatisch als Entwurf; Standard-PDF manuell; Dedupe, Storage, Pflichtklassifizierung, Mixed Tax | Upload ist erster Schritt; Werte geprüft; Kategorie und Steuer manuell bestätigt; Originale privat abrufbar |
| Steuerregeln | zentrale CRUD-Rechte, 0 %, Behandlung/Grund, Zeitraum ohne Überschneidung, historische Grenztage, Snapshot/Immutabilität | 0-%-/IHK-Fall und ein historischer Corona-Zeitraum korrekt ausgewählt und gebucht |
| Reconciliation | voll/teilweise/split/direktes Konto/reversal | offene Posten und Journale stimmen |
| Stammdaten | Tenant Scope, Validierung, Snapshots | Kunde, Lieferant, Mandat, Artikel vollständig pflegbar |

## Kontrollierter manueller Gesamttest

Erst nach grüner CI und automatisiertem Demo-E2E:

1. Frische Demo installieren, Migrationen ausführen und minimale Konten-/Steuer-/Posting-Rule-Daten seeden. In der zentralen Steuerpflege 0 %, 7 %, 19 % sowie historische 5-%-/16-%-Versionen mit Zeiträumen prüfen.
2. Legal Entity mit vollständigem Rechnungsprofil, Owner-Mapping, privatem S3-Disk, Queue Worker und Scheduler konfigurieren.
3. Kunde mit Anschrift, Steuerdaten, Bankkonto und gültigem Mandat sowie Lieferant und mindestens zwei Artikel mit unterschiedlichen Steuersätzen anlegen.
4. Bankverbindung mit akzeptierter Product ID, Zugang und ausgewähltem TAN-Verfahren anlegen.
5. Konten, Salden und Umsätze synchronisieren; Retry und erneuten Sync auf Duplikate prüfen.
6. Ausgangsrechnung mit gemischten Steuersätzen als Entwurf erstellen, freigeben und buchen. PDF visuell prüfen; PDF/A-/Attachment-Metadaten, eingebettetes XML, separates identisches XML, S3-Objekte und Downloads prüfen.
7. Drei Eingangsrechnungswege prüfen: eigenständige E-Rechnungs-XML und ZUGFeRD-/Factur-X-PDF müssen automatisch vorausgefüllte Entwürfe erzeugen; eine Standard-PDF muss nach dem Upload in einen leeren manuellen Entwurf führen. Originale, extrahiertes XML, S3-Verknüpfung und Download kontrollieren. Bei allen drei Rechnungen Aufwandskategorie und steuerliche Behandlung manuell festlegen; zusätzlich eine Rechnung mit mehreren Kategorien/Steuersätzen sowie einen IHK-/Gebührenfall mit expliziten 0 % und passender steuerlicher Behandlung testen. Ohne vollständige Klassifizierung darf keine Buchung möglich sein.
8. Je einen eingehenden und ausgehenden Umsatz einer Rechnung zuordnen; zusätzlich Teilzahlung, Split und direkte Sach-/Steuerkontierung prüfen. Offene Posten, Journale und Reversal kontrollieren.
9. Eine kleine, sichere SEPA-Überweisung mit korrekter Gegenpartei und Referenz ausführen; SCA, Retry und finalen Status dokumentieren.
10. Eine ausdrücklich autorisierte SEPA-Lastschrift mit sicherem Testbetrag und gültigem Mandat ausführen; Sequence, Fälligkeit, SCA und finalen Status dokumentieren.
11. Prüfen, dass Logs, Exceptions und Auditdaten keine PINs, TANs oder vollständigen Zugangsdaten enthalten.

Live-Zahlungen niemals nur aufgrund gemockter Tests als erfolgreich deklarieren. Vor dem Absenden müssen Zielkonto, Betrag, Mandat und Umgebung nochmals menschlich bestätigt werden.

## Definition of Done

- Alle drei CI-Pipelines sind auf allen unterstützten PHP-Versionen grün und reproduzierbar.
- Der kombinierte Demo-E2E läuft aus einer dokumentierten frischen Installation.
- Alle Zielstrecken aus der Abnahmematrix sind automatisiert abgedeckt; Live-Bank-Schritte besitzen zusätzlich ein ausgefülltes manuelles Protokoll.
- Ausgangsrechnung erzeugt ein geprüftes PDF mit eingebettetem E-Rechnungs-XML sowie dasselbe XML separat; beide liegen privat auf S3 und sind autorisiert abrufbar.
- Der Eingangsrechnungs-Workflow beginnt mit PDF/XML-Upload. Gültige E-Rechnungs-XML und Hybrid-PDF erzeugen automatisch vorausgefüllte, aber ungeprüfte Entwürfe; eine Standard-PDF führt zur vollständig manuellen Erfassung. Originale liegen privat auf S3 und sind mandantensicher verknüpft.
- Keine Eingangsrechnung kann gebucht werden, bevor Aufwands-/Buchungskategorie und steuerliche Behandlung für 100 % des Nettobetrags manuell bestätigt wurden; Mixed-Category/-Tax ist korrekt unterstützt.
- Steuercodes und zeitabhängige Versionen sind zentral und berechtigungsgeschützt pflegbar. 0 % ist ein gültiger expliziter Satz; steuerfrei, nicht steuerbar, Reverse Charge und andere Behandlungen bleiben unterscheidbar. Perioden dürfen sich nicht überschneiden, historische Belege verwenden die damalige Version und gebuchte Snapshots bleiben unverändert.
- Reconciliation stimmt für Ein-/Ausgang, Teilzahlung, Split, direkte Kontierung, Reversal und mehrere Bankkonten.
- Kunden-/Lieferanten-/Mandats-/Artikelpflege und Tenant-Isolation sind über UI-Tests belegt.
- Keine unbekannten Steuerfälle werden stillschweigend als 0 % behandelt; explizite 0-%-/Befreiungsfälle bleiben korrekt möglich. Keine Cross-Entity-Buchungen, keine verwaisten Attachments und keine still veralteten Mandate.
- Dokumentation enthält Setup, Konfiguration, Queue/Scheduler, S3, Owner Mapping, Product-ID-/SCA-Voraussetzungen, Testanleitung und bekannte bankspezifische Einschränkungen.
- Runtime, CI und verpflichtende Release-Abnahme benötigen ausschließlich PHP und keine JVM, JAR-Datei oder Java-basierten Validator.
- Jeder PR enthält: Problem, Lösung, Migration-/BC-Auswirkung, Tests, Screenshots bei UI-Änderungen, manuellen Prüfschritt und Abhängigkeiten zu den anderen Repositories.

## Gewünschter Abschlussbericht

Am Ende keine bloße Tätigkeitsliste liefern, sondern:

1. Ampel je Zielstrecke mit Beleg (Test/CI/Live-Protokoll).
2. PR-Links und Merge-Reihenfolge.
3. Ausgeführte Testkommandos samt Ergebnis.
4. Noch offene Blocker, getrennt nach Code, Konfiguration und Bank/externem Dienst.
5. Exakte Schrittfolge für den nächsten vollständigen Testlauf.
6. Keine Aussage „fertig“, solange PDF/XML/S3, Bridge-CI und der gemeinsame Demo-E2E nicht nachweislich grün sind.
