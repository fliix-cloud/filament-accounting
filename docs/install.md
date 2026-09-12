# Installation und Inbetriebnahme

Stand: 9. September 2026. Alle Befehle werden im Verzeichnis der **Laravel-Host-Anwendung** ausgeführt. Das Paket ist keine eigenständig startbare Anwendung.

## 1. Voraussetzungen

- PHP 8.3+, Composer, Laravel 13 und ein Filament-5-Panel mit Anmeldung.
- PHP-Erweiterungen gemäß Composer, insbesondere `dom`, `fileinfo` und `mbstring`.
- Eingerichtete Laravel-Datenbank, HTTPS und Schreibrechte für `storage` und `bootstrap/cache`.
- Eine deutsche Firma je Anwendungsinstanz; für die unterstützten Buchungen EUR verwenden.
- Node.js/npm für den Frontend-Build des Hosts.

Fehlt das Filament-Panel, zunächst nach der [Filament-Installationsanleitung](https://filamentphp.com/docs/5.x/introduction/installation) einrichten:

```bash
composer require filament/filament:"~5.0"
php artisan filament:install --panels
```

**Aktueller Freigabestand:** Das Paket ist noch in Entwicklung. Es gibt keinen zugesicherten Upgrade-Pfad für bestehende Buchhaltungsdatenbanken und noch offene [GoBD-Befunde](gobd.md). Eine erfolgreiche Installation ist keine Produktiv- oder Compliance-Freigabe.

## 2. Paket und Datenbank installieren

Für den aktuellen Entwicklungsstand:

```bash
composer config repositories.filament-fints-accounting vcs https://github.com/fliix-cloud/filament-fints-accounting.git
composer require fliix-cloud/filament-fints-accounting:dev-main nemiah/php-fints:@dev
composer check-platform-reqs
```

Die explizite Host-Anforderung `nemiah/php-fints:@dev` erlaubt nur dieser transitiven Entwicklungsabhängigkeit die benötigte Stabilität; die globale `minimum-stability` kann `stable` bleiben. Siehe [Composer zu Stabilitätsfreigaben](https://getcomposer.org/doc/04-schema.md#package-links). Die Host-`composer.lock` versionieren und für Deployments `composer install` verwenden. Laravel erkennt den Paket-Service-Provider automatisch. Die früheren separaten Accounting-/FinTS-Pakete nicht zusätzlich installieren.

In der Host-`.env` die üblichen Laravel-Werte für `APP_URL`, `DB_*` und einen bestehenden, gesicherten `APP_KEY` konfigurieren. Bei einer **neuen** Anwendung ohne Schlüssel einmal `php artisan key:generate` ausführen; einen vorhandenen Schlüssel nicht ersetzen.

```dotenv
APP_ENV=production
APP_DEBUG=false
ACCOUNTING_COUNTRY=DE
ACCOUNTING_DISK=local
```

`local` muss ein privater Disk außerhalb des Webroots sein. Für andere Speicher den Disk in `config/filesystems.php` einrichten und dessen Namen als `ACCOUNTING_DISK` verwenden. Belege nicht über einen öffentlichen Storage-Link bereitstellen. Für diese Einrichtung dieselbe Datenbankverbindung wie Laravel verwenden und `ACCOUNTING_DB_CONNECTION` nicht setzen: Die durchgängige Transaktionssicherheit separater Verbindungen ist noch offen.

```bash
php artisan config:clear
php artisan migrate --force
php artisan filament-accounting:install --country=DE
```

Der Installer veröffentlicht `config/filament-accounting.php`. Er legt **keine Firma und keine Benutzerrechte** an. Bei bereits vorhandener Firma wird das deutsche Konten-/Steuerprofil vorbereitet. Lokal kann alternativ `filament-accounting:install --migrate --country=DE` verwendet werden. `migrate` führt auch ausstehende Host-Migrationen aus.

### Optionale Demodaten

Für eine Demo-Installation enthält das Paket
`FilamentAccounting\Database\Seeders\AccountingDemoSeeder`. Der Host legt seinen
Demo-Benutzer an, meldet ihn für den Seeder-Aufruf an und ruft anschließend
`$this->call(\FilamentAccounting\Database\Seeders\AccountingDemoSeeder::class)` auf.
Die normalen Berechtigungen für Verkaufs- und Einkaufsentwürfe müssen erlaubt sein.
In der mitgelieferten Demo-Anwendung genügt weiterhin `php artisan db:seed`.

Der Paket-Seeder erstellt die Demo GmbH mit deutschem Kontenprofil, Kunde,
Lieferant, Beispiel-Bankverbindung des Kunden, Lastschriftmandat und zwei
Rechnungsentwürfen. Er erstellt keine Benutzer, verändert keine `.env` und ordnet
keine bestehenden Bankverbindungen um. Rechnungen werden nicht automatisch
ausgestellt oder gebucht; für eine Eingangsrechnung muss vor Abschluss ein
Originalbeleg ergänzt werden. Wiederholte Aufrufe verwenden die bestehenden
Demo-Datensätze. Der Seeder wird nicht automatisch bei Installation ausgeführt.

## 3. Panel und Darstellung anbinden

In der vorhandenen `panel()`-Methode des Panel Providers ergänzen:

```php
use FilamentAccounting\FilamentAccountingPlugin;

// An die bestehende $panel-Konfiguration anhängen:
->plugin(FilamentAccountingPlugin::make())
```

Der Panel Provider muss in `bootstrap/providers.php` registriert sein. Bei einem neuen Standardpanel lautet der Pfad `/admin`; vorhandene Panels behalten ihren eigenen Pfad.

Die Inhaltsbreite bleibt beim Host. Für die bisherige volle Breite ausdrücklich
`FilamentAccountingPlugin::make()->fullWidth()` verwenden. Das Plugin ergänzt
die Farbnamen `accounting-negative` (Blau) und `accounting-positive` (Grün) für
Bankumsätze; Standardfarben wie `primary`, `success` und `danger` bleiben
unverändert. Eigene Werte für diese beiden Accounting-Farben nach der
Plugin-Registrierung über `$panel->colors([...])` setzen.

### Funktionsschalter

Eine Funktion wird registriert, wenn sowohl ihr Konfigurationswert unter
`filament-accounting.features` als auch ihr Plugin-Schalter aktiv sind.
Die Fluent-Methoden können eine deaktivierte Konfiguration nicht überstimmen.
Alle Methoden akzeptieren `bool`, standardmäßig `true`.

| Konfiguration | Fluent-Methode | Oberfläche |
| --- | --- | --- |
| `dashboard` | `dashboard()` | Accounting-Übersichtswidget |
| `customers` | `customers()` | Kunden |
| `suppliers` | `suppliers()` | Lieferanten |
| `catalog` | `catalog()` | Katalog |
| `sales_invoices` | `salesInvoices()` | Ausgangsrechnungen |
| `purchase_invoices` | `purchaseInvoices()` | Eingangsrechnungen |
| `bank_reconciliation` | `bankReconciliation()` | Konten, Umsätze, Zahlungen, Zuordnung, SCA, Lernregeln und Banksaldenwidget |
| `journal` | `journal()` | Journal |
| `chart_of_accounts` | `chartOfAccounts()` | Kontenplan (Konfiguration standardmäßig `false`) |
| `tax_and_posting_rules` | `taxAndPostingRules()` | Steuersätze und Steuerfälle |
| `settings` | `settings()` | Firma, Bankverbindungen, Gläubiger und Mandate |
| `audit` | `audit()` | Prüfprotokoll (Konfiguration standardmäßig `false`) |

`reports` wurde als wirkungsloser Konfigurationsschlüssel entfernt.
„Auswertungen“ ist eine Navigationsgruppe für Journal, Kontenplan und Audit;
ein eigenständiges Berichtsmodul ist nicht enthalten. Schalter steuern die
Panel-Registrierung, nicht die Berechtigungen von Services oder HTTP-Routen.
Gates müssen weiterhin eingerichtet werden. Änderungen für vorhandene Hosts
stehen in der [Schema- und Release-Policy](upgrading.md).

Für die paketinternen Tailwind-Klassen ein [Filament-Theme](https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme) verwenden. Falls noch keines existiert:

```bash
php artisan make:filament-theme admin
```

Die ausgegebenen Schritte zur Vite-Einbindung ausführen. In `resources/css/filament/admin/theme.css` zusätzlich die Paketquellen aufnehmen:

```css
@source '../../../../vendor/fliix-cloud/filament-fints-accounting/src/**/*.php';
@source '../../../../vendor/fliix-cloud/filament-fints-accounting/resources/views/**/*.blade.php';
```

Den Theme-Pfad im Panel mit `->viteTheme('resources/css/filament/admin/theme.css')` registrieren, als Vite-Eingabe aufnehmen und bauen:

```bash
npm install
npm run build
```

Bei anderem Theme-Verzeichnis die relativen Pfade anpassen.

## 4. Benutzer und produktive Berechtigungen einrichten

Das Paket liefert Berechtigungsnamen unter `filament-accounting.authorization.abilities`, aber keinen Rollen-Seed. **Login und Panel-Zugang allein reichen nicht.** Nicht definierte Gates werden abgelehnt; auch ein globales `Gate::before()` ersetzt beim aktuellen Standard-Authorizer keine expliziten Gates.

### Minimale Einrichtung für einen ausdrücklich benannten Administrator

Einen vorhandenen Host-Benutzer verwenden oder einen anlegen:

```bash
php artisan make:filament-user
```

Dessen tatsächliche Benutzer-ID ermitteln und im Host `config/accounting-access.php` anlegen:

```php
<?php

return [
    'administrators' => ['42'], // Durch die tatsächliche Benutzer-ID ersetzen.
];
```

Diese Benutzer erhalten alle im Paket konfigurierten Accounting-Rechte für die einzige Firma. Für weitere Mitarbeiter gezielte Rollen/Berechtigungen im Host-Rechtesystem vergeben; sie nicht pauschal in diese Administratorliste aufnehmen.

In `App\Providers\AppServiceProvider` folgende Imports ergänzen und den Code in die **bestehende** `boot()`-Methode aufnehmen:

```php
use App\Models\User;
use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

// Innerhalb von boot():
foreach (config('filament-accounting.authorization.abilities', []) as $ability => $gate) {
    if (Gate::has($gate)) {
        continue; // Bestehende Host-Regeln nicht überschreiben.
    }

    Gate::define($gate, function (User $user, mixed $subject = null) use ($ability): bool {
        $administrators = array_map('strval', config('accounting-access.administrators', []));
        if (! $user->exists || ! in_array((string) $user->getAuthIdentifier(), $administrators, true)) {
            return false;
        }

        $entity = app(AccountingEntityResolver::class)->resolve();
        if (! $entity instanceof LegalEntity) {
            // Die erste Firma muss angelegt werden können.
            return $ability === 'manage_settings'
                && ($subject === null || ($subject instanceof LegalEntity && ! $subject->exists));
        }

        if ($subject === null) {
            return true;
        }

        return $subject instanceof LegalEntity
            ? $subject->is($entity)
            : ($subject instanceof Model
                && (string) $subject->getAttribute('legal_entity_id') === (string) $entity->getKey());
    });
}
```

Auch das Host-User-Modell muss den produktiven Filament-Zugang erlauben. Es implementiert `Filament\Models\Contracts\FilamentUser`. Für ein ausschließlich diesen Administratoren vorbehaltenes Panel die folgende Methode verwenden beziehungsweise in die bestehende Zugangskontrolle integrieren:

```php
public function canAccessPanel(\Filament\Panel $panel): bool
{
    return $panel->getId() === 'admin'
        && in_array(
            (string) $this->getAuthIdentifier(),
            array_map('strval', config('accounting-access.administrators', [])),
            true,
        );
}
```

`admin` durch die tatsächliche Panel-ID ersetzen. Bestehende Regeln für andere Panels erhalten. Die `local`/`testing`-Freigabe der Herd-Demo **nicht** produktiv übernehmen; einen vorhandenen Demo-Provider durch diese produktive Anbindung ersetzen. Danach `php artisan config:clear` ausführen.

Bei vorhandenem Rollensystem stattdessen jedes konfigurierte Gate mit Benutzerberechtigung und Firmenzugriff verbinden, oder `authorization.authorizer` auf eine eigene Implementierung von `AccountingAuthorizer` setzen. [Laravel beschreibt die Gate-Registrierung hier](https://laravel.com/framework/docs/13.x/authorization#writing-gates).

## 5. Firma und erste Belege

1. Mit dem freigegebenen Benutzer anmelden und die Firmeneinstellungen öffnen. Beim Standardpanel ist der Einrichtungsassistent unter `/admin/accounting/company-settings/setup` erreichbar.
2. Firma, Anschrift, DE/EUR, Geschäftsjahr, Steuerdaten und Rechnungsdaten vollständig eintragen. Der Assistent legt das deutsche Konten-/Steuerprofil automatisch an.
3. Falls die Firma außerhalb des Assistenten angelegt wurde, `php artisan filament-accounting:seed-profile DE` ausführen. Dieser Befehl vergibt keine Benutzerrechte.
4. Kunden/Lieferanten anlegen und die Rechnungsabläufe zuerst mit Testdaten in einer separaten Testinstallation prüfen: Rechnung erstellen, ausstellen, PDF/XML lesen und buchen; Eingangsrechnung hochladen und zuordnen.

## 6. FinTS aktivieren, falls benötigt

Die eigene registrierte FinTS-Produkt-ID konfigurieren:

```dotenv
FINTS_PRODUCT_ID=DEINE_REGISTRIERTE_PRODUKT_ID
```

```bash
php artisan config:clear
php artisan filament-accounting:sync-institutes
```

Im Panel eine Bankverbindung anlegen, Bankzugang/TAN-Verfahren konfigurieren und die erforderliche Freigabe durchführen. Konten abrufen, benötigte Konten aktivieren und den Buchhaltungskonten zuordnen. Beispiel für einen anschließenden Abruf:

```bash
php artisan filament-accounting:sync-bank --connection=BANKVERBINDUNGS_UUID --accounts --balances --transactions
```

Die UUID stammt aus der Bankverbindung. Erforderliche TAN-/SCA-Schritte werden im Panel erledigt. Eine erfolgreiche Synchronisation beweist noch keine lückenlose Bankhistorie; offene Einschränkungen stehen in [GoBD-Bereitschaft](gobd.md).

## 7. Betrieb und Abschlussprüfung

- Für Deployment den geprüften `composer.lock` übernehmen und `composer install --no-dev --optimize-autoloader` verwenden; kein unkontrolliertes `composer update` auf dem Server. Keine Verzeichnisverknüpfung auf einen veränderlichen Entwickler-Checkout verwenden.
- Datenbank, private Belege und `APP_KEY` gemeinsam sichern und die Wiederherstellung prüfen. Die Konfiguration unabhängiger Audit-Anker ist in [Betrieb](operations.md#audit-anchors) beschrieben. Erst nach tatsächlicher Prüfung des Speicherschutzes dessen Attestierung aktivieren.
- Laravel-Scheduler einrichten (`schedule:run` einmal pro Minute). Beispielsweise in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('filament-accounting:cleanup-sca')->hourly()->withoutOverlapping();
Schedule::command('filament-accounting:verify --json')->daily()->withoutOverlapping();
// Nach Einrichtung des unabhängigen Anker-Speichers:
Schedule::command('filament-accounting:audit-anchor --json')->hourly()->withoutOverlapping();
```

Ausgaben und Fehler dieser Befehle überwachen und aufbewahren. Bei `FINTS_SYNC_USE_QUEUE=true` einen überwachten `php artisan queue:work`-Prozess für die konfigurierte Queue betreiben. Automatisierte Bankabrufe ausdrücklich einplanen; erforderliche SCA bleibt ein Benutzervorgang.

Abschließend nach Firmenanlage und Konfiguration:

```bash
php artisan config:cache
php artisan filament-accounting:verify --json
```

Zusätzlich Anmeldung, sichtbare Menüs, erlaubte Aktionen und die Ablehnung eines nicht berechtigten Benutzers prüfen. Ein grüner Integritätsbericht ersetzt diese Funktionsprüfungen nicht.

**Fehlende Menüs/403:** Panel-Zugang, explizite Gates, Benutzer-ID und `features` in der Paketkonfiguration prüfen. **Fehlendes Layout:** Theme-Quellen und Vite-Build prüfen. **Bankzugriff scheitert:** Produkt-ID, Bankfreischaltung und offene SCA prüfen. **Vorhandene Buchhaltungsdaten:** Niemals `migrate:fresh` verwenden; dieses löscht Tabellen und ist ausschließlich für wegwerfbare Entwicklungsdatenbanken vorgesehen.
