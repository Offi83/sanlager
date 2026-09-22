# 🔧 Entwicklung

Diese Seite beschreibt die technische Struktur der SanLager-App sowie den Ablauf für Entwicklung, Tests und Bereitstellung.

## Projektstruktur

Die wichtigsten Verzeichnisse des Projekts:

```text
sanlager/
├── database/
│   ├── database.sqlite
│   └── migrations/
│       └── ...
├── docs/
│   └── ...
├── public/
│   ├── css/
│   ├── images/
│   ├── js/
│   │   ├── vendor/
│   │   └── *.js
│   └── index.php
├── script/
│   ├── pull.sh
│   └── push.sh
├── src/
│   ├── ArticleActions.php
│   ├── ArticleRepository.php
│   ├── BatchRepository.php
│   ├── CategoryActions.php
│   ├── CategoryRepository.php
│   ├── Database.php
│   ├── helpers.php
│   ├── LocationActions.php
│   ├── LocationRepository.php
│   ├── QrCodeGenerator.php
│   ├── StockActions.php
│   └── StockRepository.php
├── vendor/
├── .gitignore
├── composer.json
├── composer.lock
└── start.sh
```

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
* **`*Actions.php`** – verarbeitet die POST-Aktionen der `index.php` (Validierung der Eingaben, Aufruf der passenden Repository-Methoden, Redirect/JSON-Antwort). Jede `dispatch(string $action)`-Methode kümmert sich nur um die Aktionen, für die sie zuständig ist, und ignoriert alle anderen – `index.php` ruft dadurch einfach alle Action-Klassen nacheinander auf.
* **`helpers.php`** – kleine globale Funktionen (`h()`, `redirect()`, `formatDate()`, `expiryInfo()`), die sowohl im HTML-Template als auch in den Action-Klassen gebraucht werden. Wird über den `files`-Autoload-Eintrag in `composer.json` automatisch geladen.

### `database/`

Enthält die lokale SQLite-Datenbank sowie die Migrationen.

Die Datei

```text
database/database.sqlite
```

wird **nicht über GitHub verteilt**.

`database/migrations/` enthält die fortlaufend nummerierten SQL-Migrationen, über die Änderungen an der Datenbankstruktur vorgenommen werden (siehe [Datenbank-Dokumentation](10-datenbank.md)).

### `script/`

Enthält Hilfsskripte für die Verteilung der Anwendung (`pull.sh`, `push.sh`), siehe die Abschnitte weiter unten auf dieser Seite.

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

