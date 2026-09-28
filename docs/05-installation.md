# Installation

Diese Seite führt in der Reihenfolge durch die Einrichtung eines Servers: Pakete, Code und `.env`, Webserver, erster Aufruf. Danach folgen die weiteren Schritte – Datensicherung, Wochenbericht, Etikettendrucker, Pi-Terminal – auf eigenen Seiten. Zum Ausprobieren auf dem eigenen Rechner reicht [Entwicklung → Entwicklung lokal](11-entwicklung.md#entwicklung-lokal).

## Voraussetzungen

* Linux, z. B. Debian oder Raspberry Pi OS
* PHP **8.5.x** mit den Erweiterungen `pdo_sqlite`, `mbstring` und `simplexml` (QR-Codes)
* SQLite 3
* Composer
* Git
* Apache oder ein anderer PHP-fähiger Webserver

SanLager verwendet SQLite als Datenbank. Eine Installation von MySQL oder MariaDB ist nicht erforderlich.

Auf Debian bzw. Raspberry Pi OS sind das die Pakete:

```bash
sudo apt install apache2 libapache2-mod-php8.5 php8.5-sqlite3 php8.5-mbstring php8.5-xml composer git
```

PHP 8.5 ist nicht in jeder Distribution enthalten; kommt es aus einer zusätzlichen Paketquelle (z. B. `packages.sury.org`), heißen die Pakete wie oben. Ob die Erweiterungen aktiv sind, zeigt `php -m` (in der Liste müssen `pdo_sqlite`, `mbstring` und `SimpleXML` stehen).

Für den optionalen [Etikettendrucker](09-etikettendrucker.md) kommen später `php8.5-gd` und `brother_ql` hinzu.

---

## 1. Code holen

Die Beispiele gehen von `/var/www/sanlager` aus. Der Projektordner gehört dem eigenen Benutzer (der später auch `script/pull.sh` ausführt), die Gruppe `www-data` darf lesen:

```bash
sudo git clone https://github.com/Offi83/sanlager.git /var/www/sanlager
sudo chown -R $USER:www-data /var/www/sanlager
cd /var/www/sanlager
```

Den eigenen Benutzer in die Gruppe `www-data` aufnehmen, damit er (z. B. für `pull.sh` und die Datensicherung) in die Datenbank schreiben darf; danach einmal ab- und wieder anmelden:

```bash
sudo usermod -aG www-data $USER
```

PHP-Abhängigkeiten installieren (ohne die Entwicklungswerkzeuge):

```bash
composer install --no-dev --optimize-autoloader
```

## 2. Konfiguration (`.env`)

Die Vorlage `.env.example` kopieren:

```bash
cp .env.example .env
```

Für den Anfang reichen die Werte aus der Vorlage:

```env
APP_DEBUG=false
DB_DATABASE=database/database.sqlite
APP_TIMEZONE=Europe/Berlin
```

* `APP_DEBUG=false` (so in der Vorlage): Technische Fehler (z. B. der Datenbank) werden nur allgemein angezeigt und mit allen Details ins PHP-Fehlerprotokoll des Webservers geschrieben. `true` nur zum Entwickeln.
* `APP_TIMEZONE` legt fest, welcher Tag als „heute“ gilt (MHD-Ablauf, heute ausgebuchte Artikel). Ohne Angabe wird `Europe/Berlin` verwendet.

Die übrigen Einträge der Vorlage gehören zu [Datensicherung](06-datensicherung.md), [Wochenbericht](07-wochenbericht.md) und [Etikettendrucker](09-etikettendrucker.md) und werden dort erklärt.

Die `.env` enthält später SMTP-Zugangsdaten. Sie darf deshalb nur für den eigenen Benutzer und den Webserver lesbar sein:

```bash
sudo chown $USER:www-data .env
chmod 640 .env
```

**Nicht** `chmod 600`: Kann der Webserver die `.env` nicht lesen, meldet SanLager keinen Fehler, sondern läuft still mit den Standardwerten – Einstellungen wie `LABEL_OUTPUT` oder `REPORT_EXPIRY_DAYS` wirken dann in der Weboberfläche nicht.

Die `.env` wird nicht über Git versioniert und liegt außerhalb von `public/`, ist also nicht über den Webserver erreichbar.

## 3. Datenbank und Schreibrechte

Die SQLite-Datenbank muss **nicht manuell erstellt** werden: SanLager legt sie beim ersten Aufruf an und bringt sie bei späteren Aufrufen automatisch auf den neuesten Stand (Migrationen). Vorhandene Daten bleiben dabei erhalten. Wie das funktioniert, steht unter [Datenbank → Änderungen an der Datenbank](10-datenbank.md#änderungen-an-der-datenbank).

Dafür muss der Webserver-Benutzer (bei Debian/Raspberry Pi OS `www-data`) in den **Ordner** `database/` schreiben dürfen, nicht nur in die Datei `database.sqlite`: SQLite legt beim Schreiben daneben kurzzeitig eine Journal-Datei an, und beim ersten Aufruf wird die Datenbank dort neu angelegt.

```bash
sudo chown www-data:www-data database
sudo chmod 775 database
```

Gibt es die Datenbank schon (z. B. kopiert von einer anderen Installation), gehört sie ebenfalls dem Webserver:

```bash
sudo chown www-data:www-data database/database.sqlite
sudo chmod 664 database/database.sqlite
```

Der restliche Projektordner braucht für den Webserver nur Leserechte.

---

## 4. Webserver

Das öffentliche Webverzeichnis ist `public/`. Bei Apache muss daher `public/` als `DocumentRoot` verwendet werden – das komplette Projektverzeichnis darf nicht öffentlich erreichbar sein.

SanLager benötigt zur Laufzeit **keinen Internetzugriff**. Auch die JavaScript-Bibliothek für den QR-Code-Scanner (`html5-qrcode`) liegt lokal unter `public/js/vendor/` und wird nicht von einem externen CDN nachgeladen.

### Apache

Datei `/etc/apache2/sites-available/sanlager.conf`:

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

### HTTPS

Für den Produktivbetrieb sollte SanLager über HTTPS erreichbar sein. Für den Kamera-Scanner beim Buchen ist das Pflicht: Mobile Browser (z. B. iOS Safari) erlauben Kamerazugriff (`getUserMedia`) nur über eine sichere Verbindung (`https://`) oder `localhost` – über eine reine `http://`-Adresse im lokalen Netz funktioniert das Scannen nicht.

Dafür kommt ein zweiter Block `<VirtualHost *:443>` mit Zertifikat hinzu – bei einer öffentlich erreichbaren Adresse am einfachsten mit `certbot --apache`, im lokalen Netz mit einem eigenen Zertifikat. Ein eigenes Zertifikat muss auf jedem Gerät als vertrauenswürdig eingetragen werden, sonst warnt der Browser; für das Pi-Terminal steht das unter [Raspberry Pi → Eigenes Zertifikat](08-raspberry-pi.md#eigenes-zertifikat).

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

Basic Auth nur zusammen mit HTTPS verwenden, sonst gehen die Zugangsdaten unverschlüsselt durchs Netz. Beide Dateien werden nicht von Git verwaltet (siehe `.gitignore`) und bleiben beim Aktualisieren erhalten. Für den Pi im Kiosk-Modus übernimmt ein kleines Hilfsprogramm die Anmeldung, siehe [Raspberry Pi](08-raspberry-pi.md).

---

## 5. Erster Aufruf

SanLager im Browser öffnen (z. B. `https://lager.example.org`). Beim ersten Aufruf wird die Datenbank angelegt; sie enthält dann den Lagerort „Hauptlager“ und die Einheit „Stück“.

Erscheint stattdessen eine Fehlermeldung, stehen die Details im Fehlerprotokoll des Webservers (`/var/log/apache2/sanlager-error.log`) – meist fehlen Schreibrechte auf `database/` (siehe Schritt 3).

Danach unter **Verwaltung** einrichten:

1. **Lagerorte** – weitere Lagerorte (z. B. Rucksäcke, Fahrzeuge). Der erste in der Liste ist beim Buchen vorausgewählt.
2. **Kategorien** und **Einheiten**
3. **Artikel** – mit Mindestbestand je Lagerort, dann die Etiketten drucken

Den Anfangsbestand bucht man auf der Buchen-Seite mit „Einlagern“.

## 6. Weitere Einrichtung

* **[Datensicherung](06-datensicherung.md)** – bei jeder Installation einrichten: tägliche, geprüfte Sicherung per Cron.
* [Wochenbericht](07-wochenbericht.md) (optional) – wöchentliche E-Mail mit Abgelaufenem und Fehlmengen.
* [Raspberry-Pi-Terminal](08-raspberry-pi.md) (optional) – festes Buchungsterminal mit Touchdisplay.
* [Etikettendrucker](09-etikettendrucker.md) (optional) – Brother QL statt A4-Bögen.

---

## Aktualisieren

Neue Versionen kommen mit `script/pull.sh` auf den Server:

1. Auf GitHub unter „Actions“ prüfen, ob der letzte Testlauf grün ist – bei Rot nicht aktualisieren.
2. Im Projektordner ausführen:

   ```bash
   ./script/pull.sh
   ```

Das Skript sichert zuerst die Datenbank, holt dann den Code, installiert die PHP-Abhängigkeiten und führt fehlende Migrationen aus. `.env`, Datenbank, Sicherungen sowie `.htaccess`/`.htpasswd` werden nicht von Git verwaltet und bleiben unverändert. Die einzelnen Schritte stehen unter [Entwicklung → Änderungen auf dem Server bereitstellen](11-entwicklung.md#änderungen-auf-dem-server-bereitstellen).

Welcher Stand läuft, steht danach unten auf jeder Seite: „Version 0.5.0“ bei einem Release, bei späteren Pushs ergänzt um Datum und Uhrzeit, z. B. „Version 0.5.0 + Stand 28.09.2026, 14:32“ (siehe [Version in der Fußzeile](11-entwicklung.md#version-in-der-fußzeile)).
