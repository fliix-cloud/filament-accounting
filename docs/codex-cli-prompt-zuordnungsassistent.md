# Implementiere den Zuordnungs-Assistenten als echte Filament-Oberfläche

## Rolle und Arbeitsweise

Du arbeitest als verantwortlicher Senior Laravel-/Filament-Entwickler an den öffentlichen Paketen von `fliix-cloud`. Führe die Aufgabe vollständig aus: aktuellen Code untersuchen, fachliches und technisches Verhalten nachvollziehen, Implementierung erstellen, Tests ergänzen, alle relevanten Prüfungen ausführen und anschließend pro tatsächlich geändertem Repository einen eigenen Pull Request eröffnen.

Erstelle **keinen weiteren reinen Konzept- oder Dokumentations-PR**. Das Ergebnis dieser Aufgabe muss eine tatsächlich benutzbare Oberfläche und funktionierende Fachlogik enthalten.

Arbeite sorgfältig in der bestehenden Architektur und vermeide versteckte Kopplungen, duplizierte Logik und „Magie“. Prüfe zuerst die im Workspace vorhandenen Repositories und deren aktuelle Branches, Abhängigkeiten, `AGENTS.md`-Dateien, Composer-Versionen und bestehenden Tests. Die unten genannten Pfade und Klassennamen entsprechen dem zuletzt bekannten Stand, müssen aber gegen den aktuellen Code verifiziert werden.

Nutze den konfigurierten MCP-Server `laravel-boost` konsequent, um Laravel-, Livewire- und Filament-Versionen, projektspezifische Konventionen, Routen, Datenbankstruktur, Logs und verfügbare Dokumentation zu prüfen. Nutze GitHub für Branches, Pull Requests, Reviews und CI. Gib niemals Tokens oder andere Zugangsdaten aus und schreibe keine Secrets in Dateien, Logs, Commits oder PR-Beschreibungen.

## Repositories und Zuständigkeiten

Mindestens diese Projekte sind beteiligt:

- `fliix-cloud/filament-accounting`: Rechnungen, offene Posten, Kunden, Lieferanten, Steuer-/Buchungskategorien, Zuordnung und Zahlungsabgleich.
- `fliix-cloud/filament-fints`: Bankkonten und importierte Bankumsätze sowie der Einstieg in die Zuordnung.
- Die lokale Laravel-/Filament-Demo- oder Host-Anwendung, in der beide Pakete installiert sind. Ermittle den exakten Repository-Namen aus dem Workspace; behandle sie primär als Integrations- und Testumgebung.

Halte die Paketgrenzen sauber:

- `filament-fints` soll Bankdaten bereitstellen und einen stabilen Einstieg bzw. Integrationsvertrag für die Zuordnung anbieten.
- `filament-accounting` soll die buchhalterische Zuordnungslogik, offene Posten, Allokationen, Buchungskategorien und Vorschläge besitzen.
- Vermeide direkte Zugriffe auf interne Implementierungsdetails des jeweils anderen Pakets, wenn sich dies durch Contracts, Events, Adapter oder eine klar definierte Integration lösen lässt.
- Ändere ein weiteres Repository nur, wenn dies für die vollständige Lösung wirklich erforderlich ist. Eröffne dann pro Repository einen getrennten PR und beschreibe die Abhängigkeit bzw. Merge-Reihenfolge.

## Ausgangssituation

Der aktuelle Einstieg ist beispielsweise:

`/admin/accounting/reconcile?line=5f989d28-c6f0-424a-89e3-c46ad990dd0e`

Die derzeitige Seite ist funktional zu technisch und entspricht nicht dem gewünschten Zuordnungs-Assistenten:

- Sie navigiert auf eine eigene Seite, statt den Assistenten direkt aus dem Umsatz als großes Modal zu öffnen.
- Sie zeigt oben separate Aktionen wie „Split transaction“ und „Assign and post“.
- Der eigentliche Inhalt besteht aus einem generischen Formular „Direct assignment“.
- Der Auswahltyp „Sales or purchase invoice“ vermischt Ausgangs- und Eingangsrechnungen.
- Abhängig von der Umsatzrichtung erscheint anschließend nur ein einzelnes Select wie „Purchase invoices“.
- Die auswählbaren Rechnungen, offenen Beträge, Teilzahlungen, Vorschlagsgründe und Unterschiede zwischen den fachlichen Zuordnungsarten sind nicht direkt sichtbar.
- Die Splittbuchung wirkt wie ein zweiter Modus ohne verständliche Beziehung zur einfachen Zuordnung.

Zuletzt bekannte relevante Dateien im Accounting-Paket sind unter anderem:

- `src/Filament/Resources/BankStatementLineResource.php`
- `src/Filament/Resources/BankStatementLineResource/Pages/ListBankStatementLines.php`
- `src/Filament/Pages/ReconciliationPage.php`
- `resources/views/pages/reconciliation.blade.php`
- `tests/Reconciliation/ReconciliationTest.php`
- `tests/Filament/BankStatementLineResourceTest.php`
- Übersetzungen unter `lang/de` und `lang/en`

Prüfe den aktuellen Stand auf `main`, bereits gemergte PRs sowie offene PRs und Reviews, bevor du Änderungen beginnst. Erhalte vorhandene, fachlich korrekte Domain-Logik und baue darauf auf.

## Fachliche Leitentscheidung: direkte Zuordnung versus Splitt

Setze die Begriffe unmissverständlich um:

### Direkte Zuordnung

Eine direkte Zuordnung weist den gesamten noch zuordenbaren Betrag eines Umsatzes **genau einem Ziel** zu:

- einer Ausgangsrechnung,
- einer Eingangsrechnung oder
- einer Steuer-/Buchungskategorie.

Ist der Umsatzbetrag kleiner als der offene Rechnungsbetrag, entsteht automatisch eine Teilzahlung. Dafür ist **keine Splittbuchung** erforderlich.

Ein Betrag darf nicht stillschweigend über den offenen Betrag einer Rechnung hinaus gebucht werden. Bei einer Überzahlung muss die Oberfläche verständlich auf den Restbetrag hinweisen und eine fachlich saubere Wahl anbieten, beispielsweise Splittbuchung, separate Kategorie oder eine bereits im Domainmodell vorgesehene Überzahlungsbehandlung. Erfinde keine stille Rundung oder automatische Restbuchung.

### Splittbuchung

Eine Splittbuchung ist nur erforderlich, wenn ein Bankumsatz auf **mehrere Ziele** verteilt wird. Typische Fälle:

- Ein Kunde bezahlt mehrere Ausgangsrechnungen in einer Sammelüberweisung.
- Eine Zahlung begleicht mehrere Eingangsrechnungen.
- Ein Umsatz besteht aus Rechnung, Gebühr, Skonto oder einer zusätzlichen Steuerkategorie.
- Ein Umsatz wird auf mehrere Steuer-/Buchungskategorien verteilt.

Beispiel: Ein Kunde überweist 1.500 EUR für drei Rechnungen über 500 EUR. Der Umsatz wird in drei Positionen zu je 500 EUR aufgeteilt. Eine einzelne Rechnung über 1.500 EUR wird dagegen direkt zugeordnet und nicht künstlich gesplittet.

Die Summe aller Splitpositionen muss exakt dem zuordenbaren Transaktionsbetrag entsprechen. Rechne ausschließlich mit Minor Units bzw. einer bereits vorhandenen präzisen Money-Abstraktion, niemals mit unkontrollierten Floats.

## Gewünschte Oberfläche

### Einstieg als Filament-Modal

Der primäre Einstieg erfolgt über die Aktion „Zuordnen“ direkt in der Tabelle der Bankumsätze:

- Die Aktion öffnet ein großes, responsives Filament-Modal.
- Es darf keine neue Browserseite geladen werden, nur um den Assistenten zu öffnen.
- Verwende kein Iframe.
- Das Modal soll auf großen Bildschirmen nahezu die verfügbare Breite nutzen und auf kleinen Bildschirmen sinnvoll umbrechen bzw. scrollen.
- Die bestehende Reconciliation-URL bleibt als barrierearmer Deep Link und Fallback erhalten.
- Modal und Fallback-Seite müssen dieselbe Livewire-/Domain-Implementierung und dieselben Views bzw. View-Komponenten verwenden. Keine zwei auseinanderlaufenden Assistenten bauen.
- Prüfe die konkret installierte Filament-Version und verwende deren offizielle Modal-, Action-, Schema- und Livewire-APIs.

### Transaktionskopf

Zeige oben einen klar abgegrenzten, überwiegend schreibgeschützten Transaktionsbereich ähnlich dem Referenzworkflow von WISO Mein Büro. Mindestens sichtbar sein sollen, soweit vorhanden:

- Empfänger/Auftraggeber bzw. Gegenpartei,
- Betrag und Währung,
- Buchungsdatum,
- Wertstellungsdatum,
- IBAN und BIC der Gegenpartei,
- betroffenes Bankkonto,
- Buchungsart,
- Verwendungszweck,
- optional Beleg-/Referenznummer,
- aktueller Zuordnungsstatus.

Positive und negative Beträge müssen visuell eindeutig, aber nicht allein durch Farbe unterscheidbar sein. Lange Verwendungszwecke und fehlende Bankdaten dürfen das Layout nicht beschädigen.

### Vier klar getrennte Zuordnungsarten

Direkt unter dem Transaktionskopf stehen vier gut erkennbare Auswahlkarten oder Tabs:

1. **Ausgangsrechnung** – Zahlung eines Kunden bzw. Ausgleich eines Debitorenpostens.
2. **Eingangsrechnung** – Zahlung an einen Lieferanten bzw. Ausgleich eines Kreditorenpostens.
3. **Steuerkategorie** – direkte Zuordnung zu einer Buchungs-/Steuerregel.
4. **Splittbuchung** – Aufteilung auf mehrere Ziele.

Es darf keinen kombinierten Auswahlwert „Sales or purchase invoice“ bzw. „Ausgangs- oder Eingangsrechnung“ mehr geben.

Nutze die Umsatzrichtung nur für eine sinnvolle Vorauswahl:

- Geldeingang: standardmäßig Ausgangsrechnung.
- Geldausgang: standardmäßig Eingangsrechnung.

Die anderen Zuordnungsarten bleiben bei fachlichen Sonderfällen erreichbar, etwa Rückerstattungen, Gutschriften oder Umbuchungen. Falls die bestehende Domain bestimmte Kombinationen nicht unterstützt, zeige eine verständliche Erklärung statt einer technisch wirkenden Fehlermeldung.

### Ausgangsrechnungen

Zeige keine einzelne undurchsichtige Selectbox, sondern eine durchsuch- und filterbare Liste bzw. Tabelle. Mindestens folgende Informationen sind erforderlich:

- Rechnungsnummer,
- Kunde,
- Rechnungsdatum,
- Fälligkeit, sofern vorhanden,
- Bruttobetrag,
- bereits zugeordnet/bezahlt,
- offener Betrag,
- Zahlungsstatus,
- Vorschlagsrang und verständliche Treffergründe.

Unterstütze mindestens Filter für offene Rechnungen und betragsnahe Treffer sowie eine Suche über Rechnungsnummer und Kundenname. Die Auswahl muss mit einem Klick erfolgen. Teilzahlungen müssen klar vor dem Buchen ausgewiesen werden.

### Eingangsrechnungen

Stelle Eingangsrechnungen in einem eigenen Bereich dar. Mindestens sichtbar sein sollen:

- interne Belegnummer, sofern vorhanden,
- Lieferanten-Rechnungsnummer,
- Lieferant,
- Rechnungs-/Eingangsdatum,
- Bruttobetrag,
- bereits zugeordnet/bezahlt,
- offener Betrag,
- Zahlungsstatus,
- Vorschlagsrang und Treffergründe.

Verwende fachlich eindeutig den Lieferantenkontext. Ein Geschäftspartner kann unabhängig voneinander Kunde und Lieferant sein. Das Kundenobjekt darf deshalb nicht automatisch anstelle des Lieferantenobjekts verwendet oder mit ihm verschmolzen werden. Die getrennten Erstellformulare für Kunden und Lieferanten bleiben ohne verwirrende Rollen-Checkboxen bestehen.

### Steuerkategorien

Zeige eine suchbare Liste der für den konkreten Mandanten, Unternehmensstandort, das Buchungsdatum und die Umsatzrichtung gültigen Buchungs-/Steuerkategorien. Mindestens sichtbar:

- Kontonummer oder fachlicher Code, sofern vorhanden,
- Bezeichnung,
- kurze Erklärung,
- Land bzw. Regelwerk, wenn relevant,
- Steuersatz bzw. Steuerbehandlung, wenn relevant.

Beispiele sind sonstige Betriebsausgaben, Personalkosten, Versicherungen, Privatentnahmen oder Umbuchungen. Hardcode diese Kategorien nicht in die UI; nutze die vorhandenen versionierten Regeln und Konfigurationen.

**Anlagevermögen ist ausdrücklich nicht Teil dieser Aufgabe** und darf nicht als fünfte Auswahlart ergänzt werden.

### Splittbuchung

Der Splitbereich muss ohne Vorwissen verständlich sein:

- Zeige den Gesamtbetrag, die bereits verteilte Summe und den verbleibenden Betrag jederzeit sichtbar.
- Jede Position besitzt Zuordnungsart, Ziel, Betrag und optional einen fachlichen Grund/Kommentar.
- Als Positionsziel sind mindestens Ausgangsrechnung, Eingangsrechnung und Steuerkategorie möglich, soweit die Domain dies zulässt.
- Rechnungspositionen zeigen den jeweiligen offenen Betrag.
- „Restbetrag übernehmen“ darf nur den mathematisch verbleibenden Betrag einsetzen.
- Positionen können hinzugefügt und entfernt werden.
- Nullbeträge, falsche Vorzeichen, Überallokation und doppelte unzulässige Zuordnung werden verhindert.
- Buchen ist erst möglich, wenn die Summe exakt stimmt.
- Bei genau einer Position über den Gesamtbetrag soll die UI auf die einfachere direkte Zuordnung hinweisen; sie darf jedoch keine Daten verlieren.

### Fußbereich und Aktionen

Die primäre Aktion lautet sinngemäß „Zuordnen und buchen“. Daneben sind mindestens „Abbrechen“ und – falls fachlich gewünscht – „Keine Zuordnung“ vorhanden. „Keine Zuordnung“ darf den Umsatz nicht als vollständig zugeordnet markieren, sondern lässt ihn nachvollziehbar unbearbeitet bzw. dokumentiert die bewusste Entscheidung entsprechend der vorhandenen Domainregeln.

Deaktiviere die primäre Aktion sichtbar, solange Eingaben unvollständig oder fachlich ungültig sind. Zeige Validierungsfehler direkt am betroffenen Feld bzw. Splitposten.

## Lokale Vorschläge ohne kostenpflichtige KI

Implementiere oder vervollständige eine lokale, deterministische Vorschlagslogik. Es dürfen keine kostenpflichtigen oder externen KI-, Embedding- oder LLM-Dienste eingebunden werden.

Bewerte Kandidaten unter anderem anhand von:

- exakter bzw. tolerierter Betragsübereinstimmung,
- Rechnungs-/Belegnummer im Verwendungszweck,
- normalisierter IBAN,
- normalisiertem Gegenparteinamen,
- Kunden-/Lieferanten-Bankdaten,
- Buchungs-, Rechnungs- und Fälligkeitsdatum,
- bereits bestätigten Zuordnungen vergleichbarer Umsätze,
- wiederkehrenden Verwendungszweckmustern,
- Richtung und Währung.

Anforderungen:

- Jeder Vorschlag besitzt einen nachvollziehbaren Score und konkrete, übersetzte Treffergründe wie „Betrag stimmt überein“, „Rechnungsnummer gefunden“ oder „IBAN stimmt überein“.
- Vorschlagslogik ist mandanten-/unternehmensbezogen. Daten verschiedener Unternehmen dürfen nie voneinander lernen.
- Bestätigte manuelle Zuordnungen dürfen lokale Regeln bzw. Statistiken verbessern.
- Eine spätere falsche Zuordnung muss korrigierbar sein und darf das lokale Lernen nicht dauerhaft vergiften.
- Vorschläge dürfen vorsortieren und vorselektieren, aber standardmäßig nicht ohne ausdrückliche Bestätigung buchen.
- Das System muss auch ohne historische Lerndaten sinnvoll funktionieren.
- Definiere Konfidenzstufen nachvollziehbar und teste Grenzfälle.

Bevor du neue Tabellen oder Modelle einführst, prüfe die bereits vorhandenen Matcher-, Suggestion-, Allocation- und Audit-Strukturen und erweitere sie möglichst kohärent.

## Buchhalterische und technische Integrität

Die Anwendung soll deutsches Steuerrecht unterstützen, aber international einsetzbar bleiben. Beachte deshalb:

- Geldbeträge immer mit Währung und präziser Money-Darstellung behandeln.
- Steuer-/Buchungsregeln nach Land, Gültigkeitszeitraum und Mandant versionieren bzw. die vorhandene Versionierung verwenden.
- Deutsche Regeln dürfen sinnvolle Defaults sein, aber nicht als globale Annahmen in UI oder Domainlogik eingebrannt werden.
- Zuordnungen und Buchungen müssen atomar erfolgen, inklusive Sperre bzw. Schutz gegen paralleles Doppelbuchen.
- Wiederholte Requests dürfen keine doppelten Allokationen erzeugen.
- Status „nicht zugeordnet“, „teilweise zugeordnet“ und „vollständig zugeordnet“ müssen aus den Allokationen korrekt und konsistent hervorgehen.
- Eine direkte Teilzahlung aktualisiert den offenen Rechnungsbetrag korrekt.
- Eine Splittbuchung aktualisiert alle betroffenen offenen Posten korrekt.
- Gebuchte Zuordnungen müssen prüfbar sein. Änderungen erfolgen nach den bestehenden Audit-/Storno-Regeln und nicht durch stilles Überschreiben historischer Daten.
- Halte Benutzer, Zeitpunkt, Quelle, Betrag, Ziel, Vorschlags-/manuelle Entscheidung und gegebenenfalls Begründung nachvollziehbar fest.
- Berechtigungen und Mandantentrennung müssen für Lesen, Vorschläge, Zuordnen, Stornieren und Navigieren gelten.

## Navigation und wechselseitige Referenzen

Schließe den Workflow vollständig:

- In der Umsatzliste ist der Zuordnungsstatus sofort sichtbar: nicht, teilweise oder vollständig zugeordnet.
- Von einem zugeordneten Umsatz kann zu jeder betroffenen Ausgangsrechnung, Eingangsrechnung oder Buchung navigiert werden.
- Auf Ausgangs- und Eingangsrechnungen sind zugehörige Bankumsätze bzw. Zahlungen mit Betrag und Datum sichtbar und navigierbar.
- Bei Splittbuchungen werden alle Positionen verständlich zusammengefasst.
- Nach erfolgreicher Zuordnung aktualisieren sich Modal und zugrunde liegende Tabelle ohne manuellen Vollreload, soweit Filament/Livewire dies unterstützt.
- Die Deep-Link-Seite und das Modal verhalten sich fachlich identisch.

## Architekturvorgaben

- Extrahiere einen wiederverwendbaren Livewire-/Filament-Assistenten oder eine gleichwertige Komponente, die sowohl im Modal als auch auf der Fallback-Seite verwendet wird.
- Die Page darf nicht per Copy-and-paste eine zweite Zustands- oder Buchungslogik besitzen.
- Verwende Services/Actions für Domainoperationen; halte Buchungslogik aus Blade-Templates und Filament-Resource-Konfiguration heraus.
- Vermeide ein riesiges, schwer testbares Livewire-Objekt. Trenne Query/Presentation, Vorschlagsberechnung, Validierung und Finalisierung sinnvoll, ohne die bestehende Architektur unnötig umzubauen.
- Verwende stabile IDs/UUIDs, niemals Anzeigezeichenfolgen als fachliche Schlüssel.
- Alle sichtbaren Texte müssen über die vorhandene Übersetzungsstruktur mindestens auf Deutsch und Englisch verfügbar sein. Keine neuen hartcodierten UI-Texte.
- Nutze bestehende Filament-Design-Tokens und Dark-Mode-Unterstützung. Keine WISO-Farben oder proprietären Assets kopieren. Übernimm das sinnvolle Informations- und Interaktionsmodell, nicht das konkrete Erscheinungsbild.
- Achte auf Tastaturbedienung, Fokus im Modal, Screenreader-Beschriftungen und zusätzliche Statussymbole statt reiner Farbcodierung.

## Verbindliche Akzeptanzszenarien

Automatisiere diese Fälle soweit sinnvoll mit Unit-, Feature-, Livewire- oder Filament-Tests:

1. Ein Geldausgang über `115,81 EUR` öffnet den Assistenten im Modal und wählt standardmäßig „Eingangsrechnung“. Die Ausgangsrechnung bleibt als klar getrennte Auswahl erreichbar; es erscheint kein kombinierter Rechnungstyp.
2. Ein Geldeingang mit exakt passendem offenem Betrag wird einer Ausgangsrechnung direkt zugeordnet und vollständig ausgeglichen.
3. Ein Geldeingang unterhalb des offenen Rechnungsbetrags wird direkt als Teilzahlung zugeordnet, ohne Splittbuchung.
4. Ein Kunde bezahlt drei Ausgangsrechnungen in einer Überweisung. Drei Splitpositionen gleichen die drei offenen Posten aus; die Summe stimmt exakt.
5. Eine Zahlung wird auf eine Eingangsrechnung und eine Bankgebühren-/Buchungskategorie verteilt.
6. Eine direkte Überzahlung kann nicht unbemerkt über den offenen Rechnungsbetrag gebucht werden.
7. Rechnungsnummer im Verwendungszweck, exakter Betrag und passende IBAN erzeugen einen hoch priorisierten Vorschlag mit drei verständlichen Gründen.
8. Eine nur namensähnliche Gegenpartei ohne weitere Treffer wird nicht als sicherer Treffer ausgegeben.
9. Historische Bestätigungen eines Unternehmens beeinflussen keine Vorschläge eines anderen Unternehmens.
10. Zwei parallele Finalisierungsversuche erzeugen nur eine gültige Buchung/Allokation.
11. Ungültige Split-Summen, Nullbeträge, falsche Vorzeichen und Überallokationen werden serverseitig verhindert.
12. Nach dem Buchen zeigen Umsatz und Rechnung wechselseitige, berechtigungsgeschützte Referenzen.
13. Die bestehende Deep-Link-URL funktioniert weiterhin und nutzt dieselbe Assistentenimplementierung.
14. Deutsch und Englisch enthalten alle neuen Texte; die Kernoberfläche besitzt keine hartcodierten sichtbaren Strings.
15. Kunden- und Lieferantenauswahl bleiben fachlich unabhängig, auch wenn dieselbe reale Firma in beiden Rollen vorkommt.

## Tests und Qualitätskontrolle

Ermittle die im Repository definierten Qualitätsbefehle aus `composer.json`, der CI-Konfiguration und den Projektanweisungen. Führe mindestens aus, soweit im Projekt vorhanden:

- gezielte Tests für Domainlogik und Livewire-/Filament-Verhalten,
- anschließend die vollständige Testsuite,
- Laravel Pint bzw. den vorhandenen Formatter,
- PHPStan/Larastan oder die vorhandene statische Analyse,
- relevante Frontend-/Asset-Prüfungen,
- Package-Matrix bzw. Versionsmatrix der CI, falls lokal möglich.

Nutze keine pauschalen `--force`- oder destruktiven Git-Befehle. Behebe Fehler ursächlich. Überspringe keine fehlschlagenden Tests und schwäche vorhandene Assertions nicht nur ab, um grüne CI zu erhalten.

Führe zusätzlich eine manuelle Prüfung in der Demo-Anwendung durch:

- Modal aus der Umsatzliste öffnen,
- alle vier Zuordnungsarten durchgehen,
- direkte Teilzahlung prüfen,
- Split auf mehrere Ziele prüfen,
- erfolgreiche Buchung und aktualisierte Status-/Navigationsanzeige prüfen,
- responsive Darstellung und Dark Mode kurz prüfen,
- Browserkonsole und Laravel-Logs auf Fehler kontrollieren.

Wenn Browserautomation verfügbar ist, erstelle aussagekräftige Screenshots für die PR-Beschreibung oder dokumentiere die geprüften Schritte. Verwende keine echten personenbezogenen Bankdaten in Fixtures oder Screenshots.

## Git- und PR-Vorgehen

1. Prüfe in jedem betroffenen Repository `git status`, aktuellen Branch, Remote und neueste Änderungen.
2. Bewahre vorhandene, nicht zu dieser Aufgabe gehörende Änderungen des Benutzers vollständig.
3. Arbeite auf einem neuen, klar benannten Branch vom aktuellen Hauptbranch, zum Beispiel `feat/reconciliation-assistant-modal`.
4. Halte Commits fachlich zusammenhängend und verständlich.
5. Rebase oder merge den aktuellen Hauptbranch nur sicher und ohne fremde Änderungen zu überschreiben.
6. Pushe den Branch und eröffne pro geändertem Repository einen PR.
7. Verlinke zusammengehörige PRs gegenseitig und dokumentiere Abhängigkeiten sowie empfohlene Merge-Reihenfolge.
8. Die PR-Beschreibung enthält Problem, fachliche Entscheidung direkte Zuordnung/Splitt, UI-Verhalten, Architektur, Migrationen, Tests, Screenshots und bekannte Grenzen.
9. Verfolge CI bis zum Abschluss. Analysiere Fehler und korrigiere sie im selben Branch.
10. Prüfe automatisierte und menschliche Review-Kommentare einzeln. Antworte nachvollziehbar und setze berechtigte Punkte um. Markiere nichts unbegründet als erledigt.

## Nicht-Ziele

- Keine Anlagenverwaltung und kein Anlagevermögen.
- Keine externe oder kostenpflichtige KI.
- Kein Iframe als Modal-Ersatz.
- Kein bloßer Markdown-Plan ohne Implementierung.
- Keine vollständige Kopie der WISO-Oberfläche oder proprietärer Assets.
- Keine unnötige Zusammenführung von Kunden- und Lieferantenmodellen.
- Kein generisches „Sales or purchase invoice“-Feld.
- Keine Splittbuchung für eine normale einzelne Teilzahlung.

## Erwartete Abschlussmeldung

Liefere nach Abschluss eine kompakte, aber vollständige Zusammenfassung mit:

- umgesetztem Benutzerworkflow,
- wesentlichen Architekturentscheidungen,
- geänderten Repositories,
- Branch- und PR-Links,
- ausgeführten Test-/Qualitätsbefehlen und Ergebnissen,
- manuellen Prüfschritten,
- gegebenenfalls verbleibenden, konkret begründeten Grenzen.

Falls du durch fehlende Berechtigungen, nicht verfügbare Repositories, unklare Paketversionen oder eine notwendige fachliche Entscheidung tatsächlich blockiert bist, halte erst nach einer gründlichen Code- und Dokumentationsprüfung an und stelle genau die kleinste erforderliche Rückfrage. Ansonsten arbeite autonom bis zu getesteten Pull Requests.
