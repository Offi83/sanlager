# 🔧 Entwicklung

Diese Seite beschreibt die technische Struktur der SanLager-App sowie den Ablauf für Entwicklung, Tests und Bereitstellung.

## Projektstruktur

Die wichtigsten Verzeichnisse des Projekts:

```text
sanlager/
├── database/
│   └── database.sqlite
├── docs/
│   └── ...
├── public/
│   ├── css/
│   ├── images/
│   └── index.php
├── src/
│   ├── ArticleRepository.php
│   ├── BatchRepository.php
│   ├── CategoryRepository.php
│   ├── Database.php
│   └── StockRepository.php
├── vendor/
├── .gitignore
├── composer.json
├── composer.lock
├── pull.sh
└── push.sh
```

### `public/`

Enthält die öffentlich erreichbaren Dateien der Anwendung.

Die `index.php` ist der zentrale Einstiegspunkt der Webanwendung.

### `src/`

Enthält die PHP-Klassen für Datenbankzugriff und Geschäftslogik.

Die Datenbankzugriffe sind dabei von der eigentlichen Darstellung getrennt.

### `database/`

Enthält die lokale SQLite-Datenbank.

Die Datei

```text
database/database.sqlite
```

wird **nicht über GitHub verteilt**.

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

Für das Projekt steht das Skript `push.sh` zur Verfügung.

Aufruf:

```bash
./push.sh "Beschreibung der Änderung"
```

Das Skript führt dabei die notwendigen Git-Schritte aus:

1. Status prüfen
2. Änderungen hinzufügen
3. Commit erstellen
4. Änderungen nach GitHub übertragen

## Änderungen auf dem Server bereitstellen

Auf dem Server wird der aktuelle Stand aus GitHub mit `pull.sh` übernommen:

```bash
./pull.sh
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

