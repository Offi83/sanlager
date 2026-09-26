# 🔧 Entwicklung

Diese Seite beschreibt die technische Struktur der SanLager-App sowie den Ablauf für Entwicklung, Tests und Bereitstellung.

## Projektstruktur

Die wichtigsten Verzeichnisse des Projekts:

```text
sanlager/
├── beispieldaten/
│   └── beispieldaten.sqlite   (Beispieldatenbank, von push.sh erzeugt)
├── bin/
│   ├── backup.php
│   ├── migrate.php
│   └── weekly-report.php
├── database/
│   ├── database.sqlite
│   └── migrations/
│       └── ...
├── docs/
│   └── ...
├── images/
│   └── *.png          (Screenshots für das README)
├── pages/
│   └── <seite>.php    (Daten je Seite)
├── public/
│   ├── css/
│   ├── images/
│   ├── js/
│   │   ├── vendor/
│   │   └── *.js
│   └── index.php
├── script/
│   ├── demo-data.php
│   ├── pull.sh
│   ├── push.sh
│   └── screenshots.sh
├── src/
│   ├── ActionResult.php
│   ├── ArticleActions.php
│   ├── ArticleRepository.php
│   ├── Backup.php
│   ├── BatchRepository.php
│   ├── CategoryActions.php
│   ├── CategoryRepository.php
│   ├── Database.php
│   ├── helpers.php
│   ├── LocalDay.php
│   ├── LocationActions.php
│   ├── LocationRepository.php
│   ├── QrCodeGenerator.php
│   ├── ReadsInput.php
│   ├── ReportConfig.php
│   ├── StockActions.php
│   ├── StockReports.php
│   ├── StockRepository.php
│   ├── UnitActions.php
│   ├── UnitRepository.php
│   └── WeeklyReport.php
├── templates/
│   ├── helpers.php    (Bausteine: Entsorgen-/Rückgängig-Button, Etikettenbogen)
│   ├── layout/        (header.php, footer.php)
│   └── pages/         (<seite>.php, eine Vorlage pro Seite)
├── tests/
├── vendor/
├── .gitignore
├── bootstrap.php
├── composer.json
├── composer.lock
└── start.sh
```

### `bin/`

Kommandozeilen-Skripte, die nicht über den Webserver erreichbar sind: der Wochenbericht per E-Mail (`weekly-report.php`, siehe [Wochenbericht](12-wochenbericht.md)), die Datensicherung (`backup.php`, siehe [Datensicherung](13-datensicherung.md)) und `migrate.php`, das `script/pull.sh` nach einem Update aufruft. Sie nutzen wie `public/index.php` die gemeinsame `bootstrap.php` (`.env`, Zeitzone, Datenbank).

### `public/`

Enthält die öffentlich erreichbaren Dateien der Anwendung.

Die `index.php` ist der zentrale Einstiegspunkt der Webanwendung (bewusst ohne Framework). Pro Request passiert dort:

1. **POST-Aktionen** – `$action` wird an die passende `*Actions`-Klasse aus `src/` weitergereicht (siehe unten).
2. **Seitendaten** – `pages/<seite>.php` lädt die Daten für die aufgerufene Seite (`?page=…`). Hier sind noch Weiterleitungen möglich, z. B. bei einer unbekannten Artikel-ID. Unbekannte Seiten zeigen die Buchen-Seite.
3. **HTML** – `templates/layout/header.php` (Head, Navigation, Meldungen), dann die Seitenvorlage `templates/pages/<seite>.php`, dann `templates/layout/footer.php`.

Eine **neue Seite** anlegen: Namen in `$pageNames` in `public/index.php` eintragen, Vorlage `templates/pages/<name>.php` anlegen und bei Bedarf `pages/<name>.php` für die Daten; in `templates/layout/header.php` unter `$navSections` einem Bereich zuordnen – als Reiter (`tabs`) oder, bei Detailseiten, nur unter `pages`. Das Hauptmenü hat bewusst nur vier Bereiche (Buchen, Heute, Kontrolle, Verwaltung) und keine Aufklappmenüs, damit es auf dem Pi-Touchdisplay (800×480) bedienbar bleibt.

**Wichtig:** `use`-Anweisungen gelten in PHP nur für die eigene Datei. In Vorlagen und Seitendaten Klassen deshalb immer mit vollem Namen ansprechen, z. B. `new \LagerApp\QrCodeGenerator()` – `TemplatesTest` prüft das.

`public/js/` enthält das Frontend-JavaScript als eigenständige Dateien (kein PHP-Templating nötig, da sie ausschließlich über DOM-IDs/Klassen und `fetch()` mit der Anwendung interagieren). `public/js/vendor/` enthält zusätzlich extern bezogene Bibliotheken (aktuell den QR-Code-Scanner `html5-qrcode`), die lokal mitgeliefert werden, damit SanLager ohne Internetzugriff funktioniert.

### `src/`

Enthält die PHP-Klassen für Datenbankzugriff und Geschäftslogik sowie globale Helper-Funktionen. Die Datenbankzugriffe sind dabei von der eigentlichen Darstellung getrennt.

* **`*Repository.php`** – reiner Datenbankzugriff (Lesen/Schreiben) für je eine Tabelle bzw. einen fachlichen Bereich (Artikel, Kategorien, Lagerorte, Chargen, Bestand).
* **`StockRepository.php` / `StockReports.php`** – `StockRepository` bucht (Ausbuchen, Umbuchen – auch mehrere Stück über mehrere Chargen, älteste zuerst –, Entsorgen, Rückgängig) und ermittelt Bestände; `StockReports` enthält die reinen Auswertungen (Heute, MHD-Übersicht, Auffüllliste, Wochenbericht). Die Berechnung von „heute“ in der Zeitzone der Anwendung teilen sich beide über den Trait `LocalDay`.
* **`*Actions.php`** – verarbeitet die POST-Aktionen der `index.php` (Validierung der Eingaben, Aufruf der passenden Repository-Methoden). Jede `dispatch($action, $input)`-Methode bekommt die Formularwerte als Array übergeben (in der Anwendung `$_POST`), kümmert sich nur um die Aktionen, für die sie zuständig ist, und liefert für alle anderen `null` – `index.php` fragt dadurch einfach alle Action-Klassen nacheinander. Die Actions greifen nie direkt auf `$_POST` zu und senden selbst keine Header, sondern geben ein `ActionResult` (Redirect oder JSON) zurück, das `index.php` ausgibt. Dadurch lassen sie sich in Tests aufrufen.
* **`ActionResult.php`** – Ergebnis einer Aktion (Weiterleitung oder JSON-Antwort).
* **`ReadsInput.php`** – liest Formularwerte typsicher aus (`string()`, `int()`, `array()`); manipulierte Werte (z. B. ein Array statt Text) gelten als nicht ausgefüllt.
* **`helpers.php`** – kleine globale Funktionen, die sowohl in den Vorlagen als auch in den Action-Klassen gebraucht werden. Wird über den `files`-Autoload-Eintrag in `composer.json` automatisch geladen:
  * Ausgabe: `h()` (HTML-Escaping), `icon()`, `formatDate()`, `formatExpiry()`
  * Mengen: `quantityText()` („5 Rollen“), `unitText()`, `quantitiesByUnit()` („18 Stück · 12 Paar“)
  * MHD und Datum: `expiryInfo()`, `expiryWarningDays()`, `normalizeDate()`
  * Namen vergleichen: `nameKey()` (ohne Groß-/Kleinschreibung, auch bei Umlauten)
  * Anfrage und Sicherheit: `redirect()`, `userMessage()`, `isSameOriginRequest()`, `startSession()`, `flash()`, `takeFlash()`

### `database/`

Enthält die lokale SQLite-Datenbank sowie die Migrationen.

Die Datei

```text
database/database.sqlite
```

wird **nicht über GitHub verteilt**.

`database/migrations/` enthält die fortlaufend nummerierten SQL-Migrationen, über die Änderungen an der Datenbankstruktur vorgenommen werden (siehe [Datenbank-Dokumentation](10-datenbank.md)).

### `script/`

Enthält Hilfsskripte für die Verteilung der Anwendung (`pull.sh`, `push.sh`) sowie für die Doku (`screenshots.sh`, `demo-data.php`), siehe die Abschnitte weiter unten auf dieser Seite.

### `docs/`

Enthält die technische Dokumentation des Projekts.

## Entwicklung lokal

Das Projekt kann lokal über GitHub ausgecheckt werden:

```bash
git clone https://github.com/Offi83/sanlager.git
cd sanlager
```

Anschließend müssen die Abhängigkeiten installiert werden:

```bash
composer install
```

Die lokale SQLite-Datenbank (`database/database.sqlite`) wird beim ersten Aufruf automatisch angelegt. Gestartet wird mit `./start.sh` (siehe [Installation](05-installation.md#startsh)).

Zum Ausprobieren mit Beispieldaten eignet sich die Demo-Datenbank, siehe [Screenshots aktualisieren](#screenshots-aktualisieren).

## Änderungen testen

Vor dem Commit sollten Änderungen lokal getestet werden.

Für PHP kann beispielsweise die Syntax geprüft werden:

```bash
php -l public/index.php
```

Die automatisierten Tests (PHPUnit) laufen gegen eine frische In-Memory-Datenbank, auf die alle Migrationen angewendet werden. Die lokale `database.sqlite` wird dabei nicht verändert:

```bash
composer test
```

Die Tests liegen unter `tests/`:

* **Logik:** Bestandslogik in `StockRepository` (FIFO, Umbuchen, abgelaufene Chargen, Mindestbestand, Rückgängig, gleichzeitige Buchungen), POST-Aktionen (`ActionsTest`), Migrationen (`DatabaseTest`), Wochenbericht, Datensicherung, Sicherheitsfunktionen und Datums-Helper.
* **Seiten (`PagesTest`):** startet einen eigenen PHP-Entwicklungsserver mit frischer Demo-Datenbank, ruft jede Seite auf und spielt die wichtigsten Abläufe über echtes HTTP durch (Buchen, Scanner, Entsorgen, Rückgängig, abgelehnte fremde Anfragen). Jede PHP-Warnung oder -Meldung in einer Seite lässt den Test scheitern.

### Automatisch bei jedem Push (GitHub Actions)

`.github/workflows/tests.yml` führt bei jedem Push und Pull Request auf GitHub aus: `composer validate`, PHP- und JavaScript-Syntaxprüfung sowie alle Tests. Das Ergebnis zeigt das Abzeichen oben im README bzw. der Reiter „Actions“ im GitHub-Repository. **Vor einem `./script/pull.sh` auf dem Server lohnt der Blick dorthin:** ist der letzte Lauf rot, nicht aktualisieren.

**Hinweis zu Datumswerten:** MHDs werden immer im Format `JJJJ-MM-TT` gespeichert, da alle Ablaufprüfungen in SQL als Textvergleich laufen. Benutzereingaben daher stets über `normalizeDate()` prüfen. Das Datum „heute“ wird in PHP (Zeitzone `APP_TIMEZONE`) ermittelt und als Parameter an SQL übergeben, nicht per `date('now')` in SQLite (UTC).

## Sicherheit von Formularen

- **Herkunftsprüfung (CSRF-Schutz):** Jede POST-Anfrage muss von der Anwendung selbst stammen (`Origin`- bzw. `Referer`-Header passt zum Host), sonst wird sie mit 403 abgelehnt, siehe `isSameOriginRequest()` in `src/helpers.php`. Browser senden diese Header automatisch. Wer zum Testen per `curl` bucht, muss ihn selbst mitgeben, z. B. `curl -H "Origin: http://localhost:8080" -d "action=…" http://localhost:8080/`.
- **Meldungen nach einer Aktion** laufen über die Session (`flash()`/`takeFlash()`), nicht über die Adresse. Actions geben sie als zweiten Parameter von `ActionResult::redirect()` zurück, nie als `?message=`.
- **Fehlermeldungen:** Eingabefehler (`RuntimeException`) werden angezeigt, technische Fehler nur allgemein und ins Fehlerprotokoll geschrieben, siehe `userMessage()`.

## Screenshots aktualisieren

Die Screenshots im README (`images/*.png`) werden automatisch erzeugt:

```bash
./script/screenshots.sh
```

Das Skript

1. legt mit `script/demo-data.php` eine **Demo-Datenbank mit Beispieldaten** an (Hauptlager, drei Rucksäcke, 17 Artikel in den Kategorien des Einsatzes, abgelaufene und bald ablaufende Chargen, Mindestbestände, Entnahmen, Umbuchungen und eine Lieferung von heute),
2. startet dafür einen eigenen PHP-Entwicklungsserver,
3. nimmt die Seiten mit Chrome/Chromium im Headless-Modus auf, im Format des Pi-Displays (800×480, doppelte Auflösung). Seiten mit zwei Spalten werden breiter aufgenommen, damit beide Spalten zu sehen sind,
4. räumt Demo-Datenbank und Server anschließend wieder auf.

Die produktive `database.sqlite` wird dabei **nicht** verwendet. Gefunden wird Chrome unter macOS und Linux automatisch, sonst den Pfad über `CHROME=/pfad/zu/chrome ./script/screenshots.sh` angeben. Außerdem wird `sqlite3` benötigt.

Die Demo-Datenbank lässt sich auch einzeln anlegen, z. B. zum Ausprobieren:

```bash
php script/demo-data.php database/demo.sqlite
DB_DATABASE=database/demo.sqlite php -d variables_order=EGPCS -S localhost:8080 -t public
```

## Änderungen zu GitHub übertragen

Für das Projekt steht das Skript `script/push.sh` zur Verfügung.

Aufruf:

```bash
./script/push.sh "Beschreibung der Änderung"
```

Das Skript führt dabei die notwendigen Schritte aus:

1. Status prüfen (ohne Änderungen bricht es hier ab)
2. **Beispieldatenbank** `beispieldaten/beispieldaten.sqlite` neu erzeugen, siehe [Beispieldatenbank](#beispieldatenbank)
3. Änderungen hinzufügen
4. Commit erstellen
5. Änderungen nach GitHub übertragen

## Beispieldatenbank

Im Repository liegt unter `beispieldaten/beispieldaten.sqlite` eine fertige Datenbank mit Beispieldaten – dieselben wie in den Screenshots (`script/demo-data.php`). `script/push.sh` erzeugt sie bei jedem Push neu, damit sie immer zum aktuellen Stand von Code und Migrationen passt.

Die MHDs und die Buchungen „von heute“ beziehen sich auf den Tag, an dem sie erzeugt wurde. Wer sie später öffnet, sieht die Buchungen deshalb nicht mehr unter „Heute“, und es ist inzwischen mehr abgelaufen. Für einen tagesaktuellen Stand einfach selbst neu anlegen (siehe [Screenshots aktualisieren](#screenshots-aktualisieren)).

Ausprobieren, ohne die eigene Datenbank anzufassen – eine Kopie verwenden, da die App beim Buchen hineinschreibt:

```bash
cp beispieldaten/beispieldaten.sqlite /tmp/sanlager-test.sqlite
DB_DATABASE=/tmp/sanlager-test.sqlite php -d variables_order=EGPCS -S localhost:8080 -t public
```

Dann <http://localhost:8080> öffnen. Die produktive `database/database.sqlite` bleibt unberührt.

## Änderungen auf dem Server bereitstellen

Auf dem Server wird der aktuelle Stand aus GitHub mit `script/pull.sh` übernommen:

```bash
./script/pull.sh
```

Nach einer Rückfrage führt das Skript nacheinander aus:

1. **Datensicherung** mit `bin/backup.php` (siehe [Datensicherung](13-datensicherung.md)) – das Update kann Migrationen mitbringen, die die Datenbank umbauen. Scheitert die Sicherung, fragt das Skript, ob es ohne weitermachen soll.
2. **Code holen:** `git fetch` und `git reset --hard origin/main`.
3. **Abhängigkeiten installieren:** `composer install --no-dev --optimize-autoloader`, passend zur neuen `composer.lock`.
4. **Datenbank aktualisieren:** `bin/migrate.php` führt fehlende Migrationen sofort aus, damit ein Fehler gleich im Terminal steht. Gibt es noch keine Datenbank, legt es keine an (sie gehörte sonst dem Benutzer im Terminal statt dem Webserver). Hat dieser Benutzer keine Schreibrechte auf die Datenbank, wird die Migration beim nächsten Seitenaufruf nachgeholt.

Lokale Dateien wie

```text
database/database.sqlite
.htaccess
.htpasswd
```

bleiben erhalten.

## Git

Das Projekt wird über GitHub verwaltet:

```text
https://github.com/Offi83/sanlager
```

Der zentrale Branch ist:

```text
main
```

Die SQLite-Datenbank gehört ausdrücklich **nicht** zum Git-Repository.

## Grundprinzip

Die Anwendung ist bewusst schlank gehalten:

* PHP als Programmiersprache
* SQLite als Datenbank
* HTML/CSS/JavaScript für die Oberfläche
* Composer für PHP-Abhängigkeiten
* GitHub für die Versionsverwaltung
* Raspberry Pi als mögliche lokale Betriebsplattform

Ziel ist eine einfache und robuste Lagerverwaltung ohne unnötige Komplexität.

