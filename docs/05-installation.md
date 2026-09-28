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

Für den optionalen Etikettendrucker kommen `php8.5-gd` und `brother_ql` hinzu, siehe [Etikettendrucker](#etikettendrucker-optional).

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

Für den optionalen Wochenbericht per E-Mail kommen weitere Einträge hinzu, siehe [Wochenbericht](07-wochenbericht.md), ebenso für einen [Etikettendrucker](#etikettendrucker-optional). Die tägliche Datensicherung sollte bei jeder Installation eingerichtet werden, siehe [Datensicherung](06-datensicherung.md).

Die `.env`-Datei ist lokal und wird nicht über Git versioniert. Sie darf zudem nicht über den Webserver öffentlich erreichbar sein.

### Datenbank

Die SQLite-Datenbank muss **nicht manuell erstellt** werden: SanLager legt sie beim ersten Aufruf an und bringt sie bei späteren Aufrufen automatisch auf den neuesten Stand (Migrationen). Vorhandene Daten bleiben dabei erhalten. Wie das funktioniert, steht unter [Datenbank → Änderungen an der Datenbank](10-datenbank.md#änderungen-an-der-datenbank).

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

Der restliche Projektordner braucht für den Webserver nur Leserechte. Wer `script/pull.sh` oder die Datensicherung unter dem eigenen Benutzer ausführt, trägt diesen in die Gruppe `www-data` ein (`sudo usermod -aG www-data $USER`, danach neu anmelden) – siehe auch [Datensicherung](06-datensicherung.md).

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

## Etikettendrucker (optional)

> **Noch nicht mit einem echten Gerät getestet.** Die Anbindung ist programmiert und automatisch getestet (mit einem Ersatz für den Drucker), ein Brother QL-810Wc steht aber noch nicht zur Verfügung. Bis zum ersten echten Probedruck können Abmessungen, Rot/Schwarz-Druck und Fehlermeldungen vom Gerät abweichen – Rückmeldungen dazu bitte als Issue.

Ohne Etikettendrucker druckt SanLager die Etiketten auf **A4-Bögen** (2 × 4 à 105 × 74 mm). Sie sehen genauso aus wie die vom Etikettendrucker (dasselbe Bild, 62 × 105 mm, mit Rand im 105 × 74-mm-Feld) und haben immer einen roten Balken. Gedruckt werden die Bögen als **PDF** („Drucken“ öffnet es in einem neuen Tab): Darin sitzt jedes Etikett auf festen Millimetern, egal welcher Browser oder Druckdialog. Im PDF-Fenster mit Skalierung „100 %“ bzw. „Tatsächliche Größe“ drucken. (Der Browserdruck der Seite selbst übernimmt über den Systemdialog – macOS, auch aus Chrome – oder in Safari die Ränder des Druckertreibers; dann verrutscht der Bogen und es kommen Leerseiten dazu.) Mit `LABEL_OUTPUT=printer` gehen sie stattdessen direkt an einen **Brother-Etikettendrucker der QL-Serie** – ohne Druckdialog und von jedem Gerät aus (Pi, PC, Handy). Das Einzeletikett zeigt dann eine Vorschau und „Drucken“ (je Klick ein Etikett); mehrere Etiketten auf einmal gehen über die Sammeletiketten.

Das Etikett liegt quer auf der Box: oben die Kategorie als Balken, darunter links Name und Artikelnummer, rechts der QR-Code. Beispiel mit 62 mm breiter Rolle und 105 mm Länge (passt gut auf die Stirnseite einer Eurobox 300 × 400 mm):

```text
┌────────────────────────────────────────────┐
│█ VERBANDMATERIAL ██████████████████████████│  ← Balken rot (bzw. schwarz)
│                                  ┌───────┐ │
│  Mullbinde 8 cm                  │  QR   │ │
│  VB-008                          └───────┘ │
└────────────────────────────────────────────┘
```

Lange Namen werden an Leerzeichen und nach Bindestrichen umbrochen (bis zu drei Zeilen) und dabei kleiner, aber nie kleiner als die Artikelnummer; kurze Namen werden höchstens so groß, wie „Ohrthermometer“ gerade auf eine Zeile passt. Maße und Einheiten bleiben zusammen („10 × 10 cm“, „100 ml“, „Gr. 4“). Passt ein einzelnes Wort dann immer noch nicht, wird es mit Trennstrich umbrochen – nach Breite, nicht nach Silben (z. B. „Blutzuckerm-essstreifen“).

### Warum brother_ql statt Druckertreiber

Unter Linux drucken sowohl der freie CUPS-Treiber (`printer-driver-ptouch`) als auch Brothers eigene Linux-Treiber **nur schwarz**. Für **Rot/Schwarz** schickt SanLager die Etiketten deshalb über das Programm `brother_ql` aus dem Python-Paket [brother-ql-next](https://github.com/LunarEclipse363/brother_ql_next) direkt an den Drucker. CUPS wird dafür nicht gebraucht.

### Installation (Raspberry Pi / Debian)

PHP-Erweiterung `gd` (zeichnet die Etiketten) und Python:

```bash
sudo apt install php8.5-gd python3-venv
sudo systemctl restart apache2
```

`brother_ql` in einer eigenen Python-Umgebung unter `/opt/brother-ql` installieren (so bleibt das System-Python unberührt):

```bash
sudo python3 -m venv /opt/brother-ql
sudo /opt/brother-ql/bin/pip install brother-ql-next
```

Den Pfad `/opt/brother-ql/bin/brother_ql` erwartet SanLager standardmäßig (änderbar mit `BROTHER_QL`).

### Anschluss per WLAN

1. Drucker ins normale WLAN bringen (nicht „Wireless Direct“, bei dem er ein eigenes Netz aufspannt) – z. B. mit Brothers Einrichtungsprogramm oder über die Weboberfläche des Druckers.
2. Im Router eine **feste IP-Adresse** für den Drucker reservieren.
3. Energiesparmodus prüfen: Meldet sich der Drucker nach einiger Zeit aus dem WLAN ab, das in den Druckereinstellungen abschalten.
4. Vom Pi aus testen, ob der Drucker erreichbar ist:

   ```bash
   nc -z -v 192.168.1.50 9100
   ```

In der `.env`: `LABEL_PRINTER=tcp://192.168.1.50:9100`.

Über WLAN kann der Drucker **keinen Status zurückmelden**. SanLager meldet deshalb „an den Drucker gesendet“; ob wirklich gedruckt wurde (Rolle leer, Deckel offen, falsche Rolle), ist nur am Gerät zu sehen. Nur wenn der Drucker gar nicht erreichbar ist, gibt es eine Fehlermeldung.

### Anschluss per USB

1. Am QL-810W(c) **„Editor Lite“ ausschalten**: die Editor-Lite-Taste gedrückt halten, bis die grüne Lampe ausgeht. Mit Editor Lite meldet sich der Drucker als USB-Speicher und nimmt keine Druckaufträge an.
2. Drucker anschließen und prüfen, ob `/dev/usb/lp0` erscheint (`ls /dev/usb/`).
3. Dem Webserver erlauben, auf den Drucker zu schreiben, danach Apache neu starten:

   ```bash
   sudo usermod -aG lp www-data
   sudo systemctl restart apache2
   ```

In der `.env`: `LABEL_PRINTER=file:///dev/usb/lp0`.

Per USB meldet der Drucker zurück, ob gedruckt wurde. SanLager zeigt dann „gedruckt“ bzw. Fehler wie „Keine Rolle eingelegt“, „Deckel offen“ oder „Falsche Rolle eingelegt“.

### Einstellungen (.env)

```env
LABEL_OUTPUT=printer
LABEL_PRINTER=tcp://192.168.1.50:9100
LABEL_PRINTER_MODEL=QL-810W
LABEL_WIDTH_MM=62
LABEL_LENGTH_MM=105
LABEL_RED=true
BROTHER_QL=/opt/brother-ql/bin/brother_ql
```

| Einstellung | Bedeutung | Standard |
|---|---|---|
| `LABEL_OUTPUT` | `a4` = A4-Bögen (als PDF zum Drucken), `printer` = Etikettendrucker | `a4` |
| `LABEL_PRINTER` | `tcp://<IP>:9100` (WLAN/Netzwerk) oder `file:///dev/usb/lp0` (USB) | – |
| `LABEL_PRINTER_MODEL` | Modell, wie `brother_ql` es nennt (QL-810Wc = `QL-810W`) | `QL-810W` |
| `LABEL_WIDTH_MM` | Breite der Rolle = Höhe des Etiketts auf der Box | `62` |
| `LABEL_LENGTH_MM` | Länge eines Etiketts (40–300 mm) | `105` |
| `LABEL_RED` | Kategorie-Balken rot statt schwarz | `false` |
| `BROTHER_QL` | Pfad zum Programm `brother_ql` | `/opt/brother-ql/bin/brother_ql` |

Unterstützt werden **Endlosrollen** (keine vorgestanzten Etiketten). Name, QR-Code und Balken passen sich der Rollenbreite an:

| `LABEL_WIDTH_MM` | Rolle (Beispiel) | Hinweis |
|---|---|---|
| `62` | DK-22205 (schwarz), **DK-22251 (rot/schwarz)** | empfohlen, QR-Code ca. 36 mm |
| `50`, `38`, `54` | DK-22223 (50 mm), DK-22225 (38 mm) | |
| `29` | DK-22210 | kleinste sinnvolle Breite, QR-Code ca. 17 mm |
| `102` | DK-22243 | nur QL-1050/1060N/1100/1110NWB/1115NWB |

**Rot/Schwarz** (`LABEL_RED=true`) geht nur mit der QL-800-Serie (QL-800, QL-810W, QL-820NWB) und der 62-mm-Rolle **DK-22251**. Solange diese Rolle eingelegt ist, muss `LABEL_RED=true` bleiben – `brother_ql` verlangt das auch, wenn gar nichts rot gedruckt wird. Umgekehrt bricht der Drucker ab, wenn `LABEL_RED=true` gesetzt, aber eine schwarze Rolle eingelegt ist.

Ist eine Einstellung ungültig (z. B. `LABEL_RED=true` mit 29-mm-Rolle), zeigen die Etikettenseiten den Fehler an und bieten so lange die A4-Bögen an.

### Erster Test

Vor dem ersten Druck aus SanLager `brother_ql` einmal von Hand aufrufen (mit einem beliebigen Bild):

```bash
/opt/brother-ql/bin/brother_ql -b network -m QL-810W -p tcp://192.168.1.50:9100 print -l 62red --red bild.png
```

Bei USB `-b linux_kernel -p file:///dev/usb/lp0`, und zwar als Webserver-Benutzer (`sudo -u www-data …`), damit auch die Berechtigung geprüft wird. Danach in SanLager ein Etikett drucken und prüfen: Ist das Rot sauber, lässt sich der QR-Code mit dem Hand-Scanner lesen, passt die Länge auf die Box? Die tatsächliche Länge kann um einige Millimeter abweichen, dann `LABEL_LENGTH_MM` anpassen.

Fehlermeldungen von `brother_ql` stehen vollständig im Fehlerprotokoll des Webservers (z. B. `/var/log/apache2/sanlager-error.log`).

---

## Aktualisieren

Neue Versionen kommen mit `script/pull.sh` auf den Server:

1. Auf GitHub unter „Actions“ prüfen, ob der letzte Testlauf grün ist – bei Rot nicht aktualisieren.
2. Im Projektordner ausführen:

   ```bash
   ./script/pull.sh
   ```

Das Skript sichert zuerst die Datenbank, holt dann den Code, installiert die PHP-Abhängigkeiten und führt fehlende Migrationen aus. `.env`, Datenbank, Sicherungen sowie `.htaccess`/`.htpasswd` werden nicht von Git verwaltet und bleiben unverändert. Die einzelnen Schritte stehen unter [Entwicklung → Änderungen auf dem Server bereitstellen](11-entwicklung.md#änderungen-auf-dem-server-bereitstellen).

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
