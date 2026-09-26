# Installation

## Voraussetzungen

Für den Betrieb von SanLager werden benötigt:

* Linux, Raspberry Pi OS oder macOS (für Entwicklung/Test)
* PHP **8.5.x** mit den Erweiterungen `pdo_sqlite`, `mbstring` und `simplexml` (QR-Codes)
* SQLite 3
* Composer
* Git
* aktueller Webbrowser
* Apache oder ein anderer PHP-fähiger Webserver für den Produktivbetrieb

SanLager verwendet SQLite als Datenbank. Eine Installation von MySQL oder MariaDB ist nicht erforderlich.

Auf Debian bzw. Raspberry Pi OS sind das die Pakete:

```bash
sudo apt install apache2 libapache2-mod-php8.5 php8.5-sqlite3 php8.5-mbstring php8.5-xml composer git
```

PHP 8.5 ist nicht in jeder Distribution enthalten; kommt es aus einer zusätzlichen Paketquelle (z. B. `packages.sury.org`), heißen die Pakete wie oben. Ob die Erweiterungen aktiv sind, zeigt `php -m` (in der Liste müssen `pdo_sqlite`, `mbstring` und `SimpleXML` stehen).

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

Bei jedem Seitenaufruf prüft SanLager (`src/Database.php`), ob Migrationen fehlen, und führt sie automatisch aus.

Bei einer bestehenden Datenbank bleiben die vorhandenen Daten erhalten. Bereits ausgeführte Migrationen werden erkannt und nicht erneut ausgeführt.

Ein manueller Migrationsbefehl ist nicht nötig. `script/pull.sh` ruft nach einem Update trotzdem `bin/migrate.php` auf, damit ein Fehler gleich im Terminal steht (siehe [Aktualisieren](#aktualisieren)).

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

### Apache-Beispiel

Beispiel für eine Installation unter `/var/www/sanlager`, Datei `/etc/apache2/sites-available/sanlager.conf`:

```apache
<VirtualHost *:80>
    ServerName lager.example.org

    DocumentRoot /var/www/sanlager/public

    <Directory /var/www/sanlager/public>
        Require all granted
        # Erlaubt eine .htaccess mit Zugriffsschutz, siehe unten.
        AllowOverride AuthConfig
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/sanlager-error.log
    CustomLog ${APACHE_LOG_DIR}/sanlager-access.log combined
</VirtualHost>
```

Aktivieren:

```bash
sudo a2ensite sanlager
sudo systemctl reload apache2
```

`ServerName` und Pfad an die eigene Installation anpassen. Weitere Regeln (z. B. `mod_rewrite`) braucht SanLager nicht: Alle Seiten laufen über `public/index.php?page=…`.

Für HTTPS (siehe oben) kommt ein zweiter Block `<VirtualHost *:443>` mit Zertifikat hinzu – bei einer öffentlich erreichbaren Adresse am einfachsten mit `certbot --apache`, im lokalen Netz mit einem eigenen Zertifikat.

### Schreibrechte

Der Webserver-Benutzer (bei Debian/Raspberry Pi OS `www-data`) muss in den **Ordner** `database/` schreiben dürfen, nicht nur in die Datei `database.sqlite`: SQLite legt beim Schreiben daneben kurzzeitig eine Journal-Datei an, und beim ersten Aufruf wird die Datenbank dort neu angelegt.

```bash
cd /var/www/sanlager
sudo chown www-data:www-data database
sudo chmod 775 database
```

Gibt es die Datenbank schon (z. B. kopiert von einer anderen Installation), gehört sie ebenfalls dem Webserver:

```bash
sudo chown www-data:www-data database/database.sqlite
sudo chmod 664 database/database.sqlite
```

Der restliche Projektordner braucht für den Webserver nur Leserechte. Wer `script/pull.sh` oder die Datensicherung unter dem eigenen Benutzer ausführt, trägt diesen in die Gruppe `www-data` ein (`sudo usermod -aG www-data $USER`, danach neu anmelden) – siehe auch [Datensicherung](13-datensicherung.md).

### Zugriffsschutz (optional)

SanLager hat keine eigene Anmeldung. Im lokalen Netz des Lagers ist das meist auch nicht nötig. **Wenn die Instanz öffentlich erreichbar ist**, sollte der Webserver den Zugang schützen, z. B. mit HTTP Basic Auth.

Passwortdatei **außerhalb** von `public/` anlegen (`htpasswd` stammt aus dem Paket `apache2-utils`):

```bash
sudo htpasswd -c /var/www/sanlager/.htpasswd lager
```

Weitere Benutzer ohne `-c` hinzufügen – `-c` legt die Datei neu an und löscht vorhandene Einträge.

Dann `public/.htaccess` anlegen (dafür braucht es `AllowOverride AuthConfig`, siehe Beispiel oben):

```apache
AuthType Basic
AuthName "SanLager"
AuthUserFile /var/www/sanlager/.htpasswd
Require valid-user
```

Basic Auth nur zusammen mit HTTPS verwenden, sonst gehen die Zugangsdaten unverschlüsselt durchs Netz. Beide Dateien werden nicht von Git verwaltet (siehe `.gitignore`) und bleiben beim Aktualisieren erhalten. Für den Pi im Kiosk-Modus übernimmt ein kleines Hilfsprogramm die Anmeldung, siehe [Raspberry Pi](90-raspberry-pi.md).

---

## Aktualisieren

Neue Versionen kommen mit `script/pull.sh` auf den Server:

1. Auf GitHub unter „Actions“ prüfen, ob der letzte Testlauf grün ist – bei Rot nicht aktualisieren.
2. Im Projektordner ausführen:

   ```bash
   ./script/pull.sh
   ```

Das Skript sichert zuerst die Datenbank (`bin/backup.php`), holt dann den Code, installiert die PHP-Abhängigkeiten (`composer install --no-dev`) und führt fehlende Migrationen aus. Einzelheiten stehen in der [Entwicklungsdokumentation](11-entwicklung.md#änderungen-auf-dem-server-bereitstellen).

`.env`, Datenbank, Sicherungen sowie `.htaccess`/`.htpasswd` werden nicht von Git verwaltet und bleiben beim Update unverändert.

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
