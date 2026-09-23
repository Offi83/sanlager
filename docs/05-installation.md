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

SanLager verwendet eine `.env`-Datei. Eine Vorlage dafür liegt als `.env.example` im Projekt und kann kopiert werden:

```bash
cp .env.example .env
```

Beispiel:

```env
DB_DATABASE=database/database.sqlite
APP_TIMEZONE=Europe/Berlin
```

Auf dem Produktivserver `APP_DEBUG=false` setzen: Technische Fehler (z. B. der Datenbank) werden dann nur allgemein angezeigt und mit allen Details ins PHP-Fehlerprotokoll des Webservers geschrieben.

`APP_TIMEZONE` legt fest, welcher Tag als „heute“ gilt (MHD-Ablauf, heute ausgebuchte Artikel). Ohne Angabe wird `Europe/Berlin` verwendet.

Für den optionalen Wochenbericht per E-Mail kommen weitere Einträge hinzu, siehe [Wochenbericht](12-wochenbericht.md). Die tägliche Datensicherung sollte bei jeder Installation eingerichtet werden, siehe [Datensicherung](13-datensicherung.md).

Die `.env`-Datei ist lokal und wird nicht über Git versioniert. Sie darf zudem nicht über den Webserver öffentlich erreichbar sein.

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

Für den Produktivbetrieb sollte SanLager über HTTPS erreichbar sein. Das ist insbesondere für den Kamera-Scanner beim Buchen erforderlich: Mobile Browser (z. B. iOS Safari) erlauben Kamerazugriff (`getUserMedia`) nur über eine sichere Verbindung (`https://`) oder `localhost` – über eine reine `http://`-Adresse im lokalen Netz funktioniert das Scannen nicht.

SanLager benötigt zur Laufzeit **keinen Internetzugriff**. Auch die JavaScript-Bibliothek für den QR-Code-Scanner (`html5-qrcode`) liegt lokal unter `public/js/vendor/` und wird nicht von einem externen CDN nachgeladen.

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
