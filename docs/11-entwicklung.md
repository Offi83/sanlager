# 🔧 Entwicklung

Diese Seite beschreibt die technische Struktur der SanLager-App sowie den Ablauf für Entwicklung, Tests und Bereitstellung.

## Projektstruktur

Die wichtigsten Verzeichnisse des Projekts:

```text
sanlager/
├── bin/
│   ├── backup.php
│   └── weekly-report.php
├── database/
│   ├── database.sqlite
│   └── migrations/
│       └── ...
├── docs/
│   └── ...
├── images/
│   └── *.png          (Screenshots für das README)
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
│   ├── LocationActions.php
│   ├── LocationRepository.php
│   ├── QrCodeGenerator.php
│   ├── ReadsInput.php
│   ├── ReportConfig.php
│   ├── StockActions.php
│   ├── StockRepository.php
│   └── WeeklyReport.php
├── tests/
├── vendor/
├── .gitignore
├── bootstrap.php
├── composer.json
├── composer.lock
└── start.sh
```

### `bin/`

Kommandozeilen-Skripte, die nicht über den Webserver erreichbar sind: der Wochenbericht per E-Mail (`weekly-report.php`, siehe [Wochenbericht](12-wochenbericht.md)) und die Datensicherung (`backup.php`, siehe [Datensicherung](13-datensicherung.md)). Sie nutzen wie `public/index.php` die gemeinsame `bootstrap.php` (`.env`, Zeitzone, Datenbank).

### `public/`

Enthält die öffentlich erreichbaren Dateien der Anwendung.

Die `index.php` ist der zentrale Einstiegspunkt der Webanwendung. Pro Request passiert dort:

1. **POST-Aktionen** – `$action` wird an die passende `*Actions`-Klasse aus `src/` weitergereicht (siehe unten).
2. **GET-Datenaufbau** – anhand von `$page` werden die für die jeweilige Seite nötigen Daten aus den `*Repository`-Klassen geladen.
3. **HTML** – ein großer if/elseif-Block anhand von `$page` rendert die passende Seite.

`public/js/` enthält das Frontend-JavaScript als eigenständige Dateien (kein PHP-Templating nötig, da sie ausschließlich über DOM-IDs/Klassen und `fetch()` mit der Anwendung interagieren). `public/js/vendor/` enthält zusätzlich extern bezogene Bibliotheken (aktuell den QR-Code-Scanner `html5-qrcode`), die lokal mitgeliefert werden, damit SanLager ohne Internetzugriff funktioniert.

### `src/`

Enthält die PHP-Klassen für Datenbankzugriff und Geschäftslogik sowie globale Helper-Funktionen. Die Datenbankzugriffe sind dabei von der eigentlichen Darstellung getrennt.

* **`*Repository.php`** – reiner Datenbankzugriff (Lesen/Schreiben) für je eine Tabelle bzw. einen fachlichen Bereich (Artikel, Kategorien, Lagerorte, Chargen, Bestand).
* **`*Actions.php`** – verarbeitet die POST-Aktionen der `index.php` (Validierung der Eingaben, Aufruf der passenden Repository-Methoden). Jede `dispatch($action, $input)`-Methode bekommt die Formularwerte als Array übergeben (in der Anwendung `$_POST`), kümmert sich nur um die Aktionen, für die sie zuständig ist, und liefert für alle anderen `null` – `index.php` fragt dadurch einfach alle Action-Klassen nacheinander. Die Actions greifen nie direkt auf `$_POST` zu und senden selbst keine Header, sondern geben ein `ActionResult` (Redirect oder JSON) zurück, das `index.php` ausgibt. Dadurch lassen sie sich in Tests aufrufen.
* **`ActionResult.php`** – Ergebnis einer Aktion (Weiterleitung oder JSON-Antwort).
* **`ReadsInput.php`** – liest Formularwerte typsicher aus (`string()`, `int()`, `array()`); manipulierte Werte (z. B. ein Array statt Text) gelten als nicht ausgefüllt.
* **`helpers.php`** – kleine globale Funktionen (`h()`, `redirect()`, `formatDate()`, `expiryInfo()`, `normalizeDate()`), die sowohl im HTML-Template als auch in den Action-Klassen gebraucht werden. Wird über den `files`-Autoload-Eintrag in `composer.json` automatisch geladen.

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

Die lokale SQLite-Datenbank wird separat benötigt bzw. angelegt.

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

1. legt mit `script/demo-data.php` eine **Demo-Datenbank mit Beispieldaten** an (Hauptlager, drei Rucksäcke, 15 Artikel, abgelaufene und bald ablaufende Chargen, Mindestbestände, Entnahmen von heute),
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

Das Skript führt dabei die notwendigen Git-Schritte aus:

1. Status prüfen
2. Änderungen hinzufügen
3. Commit erstellen
4. Änderungen nach GitHub übertragen

## Änderungen auf dem Server bereitstellen

Auf dem Server wird der aktuelle Stand aus GitHub mit `script/pull.sh` übernommen:

```bash
./script/pull.sh
```

Das Skript aktualisiert ausschließlich den versionierten Anwendungscode.

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

