# Installation

## Voraussetzungen

Für den Betrieb von SanLager werden benötigt:

* Linux, Raspberry Pi OS oder macOS (für Entwicklung/Test)
* PHP **8.5.x**
* SQLite 3
* Composer
* Git
* aktueller Webbrowser
* Apache oder ein anderer PHP-fähiger Webserver für den Produktivbetrieb

SanLager verwendet SQLite als Datenbank. Eine Installation von MySQL oder MariaDB ist nicht erforderlich.

---

## Installation

Repository klonen:

```bash
git clone https://github.com/Offi83/sanlager.git
cd sanlager
```

PHP-Abhängigkeiten installieren:

```bash
composer install
```

Für einen Produktivserver:

```bash
composer install --no-dev --optimize-autoloader
```

### Konfiguration

SanLager verwendet eine `.env`-Datei.

Beispiel:

```env
DB_DATABASE=database/database.sqlite
```

Die `.env`-Datei darf nicht über den Webserver öffentlich erreichbar sein.

### Datenbank

Die SQLite-Datenbank wird von SanLager automatisch angelegt, wenn sie noch nicht vorhanden ist.

Eine `database.sqlite` muss **nicht manuell erstellt** werden.

Beim Start prüft SanLager automatisch, ob Migrationen fehlen, und führt diese über `database.php` aus.

Bei einer bestehenden Datenbank bleiben die vorhandenen Daten erhalten. Bereits ausgeführte Migrationen werden erkannt und nicht erneut ausgeführt.

Es gibt **kein separates Migration-Script** und keinen manuellen Migrationsbefehl.

---

## Webserver

Das öffentliche Webverzeichnis ist:

```text
public/
```

Bei Apache muss daher `public/` als `DocumentRoot` verwendet werden.

Das komplette Projektverzeichnis darf nicht öffentlich erreichbar sein.

Für den Produktivbetrieb sollte SanLager über HTTPS erreichbar sein.

---

## `start.sh`

Für Entwicklung und Tests kann SanLager über `start.sh` gestartet werden:

```bash
./start.sh
```

Das Script startet den PHP-Entwicklungsserver und stellt SanLager anschließend unter

```text
http://localhost:8080
```

bereit.

Der PHP-Entwicklungsserver ist für Entwicklung und Tests gedacht und nicht als dauerhafter Produktivserver.
