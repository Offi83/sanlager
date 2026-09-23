# 💾 Datensicherung

Alle Daten von SanLager liegen in einer einzigen SQLite-Datei (standardmäßig `database/database.sqlite`). Geht sie verloren, etwa durch einen Defekt der SD-Karte oder Festplatte oder durch versehentliches Überschreiben, sind Bestand und Buchungshistorie weg. Deshalb sollte täglich automatisch gesichert werden.

`bin/backup.php` erledigt das:

* legt eine **konsistente Kopie** an, auch während gerade gebucht wird (SQLite `VACUUM INTO`, kein einfaches Kopieren der Datei)
* **prüft jede Sicherung** (öffnen, `PRAGMA integrity_check`, erwartete Tabellen vorhanden); schlägt das fehl, wird die Datei verworfen und ein Fehler gemeldet
* **löscht alte Sicherungen** nach `BACKUP_KEEP_DAYS` Tagen; die neueste Sicherung bleibt immer erhalten
* setzt die Dateirechte auf „nur Besitzer“ (`600`), weil die Sicherung den kompletten Lagerbestand enthält

Dateinamen: `sanlager-JJJJ-MM-TT_HHMMSS.sqlite`. Andere Dateien im Sicherungsordner werden nie angefasst.

---

## Konfiguration

In der `.env`:

```env
BACKUP_DIR=/media/usb/sanlager-backups
BACKUP_KEEP_DAYS=30
```

| Einstellung        | Standard   | Bedeutung |
|--------------------|------------|-----------|
| `BACKUP_DIR`       | `backups/` | Zielordner, relativ zum Projektordner oder absolut. Wird bei Bedarf angelegt. |
| `BACKUP_KEEP_DAYS` | `30`       | Aufbewahrung in Tagen. |

**Wichtig:** Eine Sicherung auf demselben Datenträger schützt vor Bedienfehlern, aber **nicht vor einem Defekt** dieses Datenträgers. Besser ist ein USB-Stick, ein NAS oder zusätzlich eine regelmäßige Kopie des Sicherungsordners auf einen anderen Rechner.

Der Standardordner `backups/` liegt außerhalb von `public/`, ist also nicht über den Webserver erreichbar, und wird nicht von Git erfasst.

---

## Testen

```bash
php bin/backup.php
```

Ausgabe bei Erfolg, z. B.:

```text
[2026-09-23 02:00] Sicherung angelegt: /media/usb/sanlager-backups/sanlager-2026-09-23_020000.sqlite (124 KB)
```

Bei einem Fehler (Ordner nicht beschreibbar, Prüfung fehlgeschlagen …) steht die Ursache auf der Fehlerausgabe, und das Skript endet mit Exit-Code 1.

---

## Automatisch (Cron)

Täglich um 2 Uhr nachts:

```bash
crontab -e
```

```cron
0 2 * * *  cd /var/www/sanlager && php bin/backup.php >> backups/backup.log 2>&1
```

Den Pfad durch das tatsächliche Projektverzeichnis ersetzen. Der Cron-Job muss unter einem Benutzer laufen, der die Datenbank lesen und in den Sicherungsordner schreiben darf. Ab und zu einen Blick ins Log werfen – eine Sicherung, die unbemerkt nicht läuft, hilft im Ernstfall nicht.

---

## Wiederherstellen

1. Passende Sicherung auswählen (Datum im Dateinamen).
2. Die aktuelle Datenbank zur Sicherheit umbenennen statt löschen:

   ```bash
   mv database/database.sqlite database/database.sqlite.defekt
   ```

3. Sicherung an ihre Stelle kopieren und die Rechte so setzen, dass der Webserver sie lesen und schreiben kann:

   ```bash
   cp /media/usb/sanlager-backups/sanlager-2026-09-23_020000.sqlite database/database.sqlite
   chown www-data:www-data database/database.sqlite
   chmod 660 database/database.sqlite
   ```

   Benutzer und Gruppe (`www-data`) an den eigenen Webserver anpassen.

4. SanLager im Browser öffnen und prüfen. Fehlende Migrationen werden beim ersten Aufruf automatisch nachgezogen.

Buchungen seit dem Zeitpunkt der Sicherung sind danach nicht mehr enthalten und müssen ggf. nachgetragen werden.
