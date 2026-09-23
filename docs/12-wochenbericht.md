# 📧 Wochenbericht per E-Mail

SanLager kann dem Lagerverantwortlichen einmal pro Woche einen Bericht per E-Mail schicken. Er enthält:

* **Abgelaufenes Material**: MHD überschritten, je Lagerort mit Menge
* **MHD läuft bald ab**: innerhalb der nächsten 90 Tage (einstellbar)
* **Unter Mindestbestand**: je überwachtem Lagerort mit Fehlmenge; abgelaufenes Material zählt dabei nicht als Bestand
* **Entnahmen der letzten sieben Tage**: je Artikel und Lagerort (nur Ausbuchungen, keine Umbuchungen)

Die wichtigsten Zahlen stehen schon im Betreff, z. B.

```text
SanLager Wochenbericht KW 39/2026 – 2 abgelaufen, 1 MHD bald erreicht, 4 unter Mindestbestand
```

Gibt es nichts zu beanstanden, kommt trotzdem eine Mail („keine Auffälligkeiten“). So merkt man, wenn der Versand ausfällt.

---

## Konfiguration

Der Bericht wird **ausschließlich über die `.env`-Datei auf dem Server** eingestellt. In der Weboberfläche gibt es dafür bewusst keine Einstellungen: Die Werte ändern sich selten, enthalten SMTP-Zugangsdaten und sollen nicht von jedem geändert werden können, der im Lager bucht.

```env
MAILER_DSN=smtp://benutzer:passwort@smtp.example.org:587?require_tls=true
REPORT_FROM="SanLager <lager@example.org>"
REPORT_RECIPIENTS=materialwart@example.org, bereitschaftsleitung@example.org
REPORT_EXPIRY_DAYS=90
APP_URL=https://lager.example.org
```

| Einstellung          | Pflicht | Bedeutung |
|----------------------|---------|-----------|
| `MAILER_DSN`         | ja      | SMTP-Zugang des Mailkontos, über das versendet wird. Sonderzeichen in Benutzername/Passwort URL-kodieren (`@` → `%40`, `:` → `%3A`, `/` → `%2F`). |
| `REPORT_FROM`        | ja      | Absender, mit oder ohne Namen. Muss meist zum SMTP-Konto passen. |
| `REPORT_RECIPIENTS`  | ja      | Eine oder mehrere Empfängeradressen, durch Komma getrennt. |
| `REPORT_EXPIRY_DAYS` | nein    | Vorlaufzeit für „MHD läuft bald ab“ in Tagen (1–365, Standard 90). |
| `APP_URL`            | nein    | Adresse der Weboberfläche. Ist sie gesetzt, sind die Artikel in der Mail verlinkt. |
| `APP_TIMEZONE`       | nein    | Bestimmt, was „heute“ und „letzte Woche“ bedeuten (Standard `Europe/Berlin`). |

Beispiele für `MAILER_DSN`:

```env
# Port 587 mit STARTTLS (üblich)
MAILER_DSN=smtp://lager%40example.org:geheim@smtp.example.org:587?require_tls=true

# Port 465 mit SSL/TLS von Anfang an
MAILER_DSN=smtps://lager%40example.org:geheim@smtp.example.org:465
```

**STARTTLS immer mit `?require_tls=true` verwenden.** Ohne diese Option wird STARTTLS nur genutzt, wenn der Mailserver es anbietet – andernfalls gehen Mail und SMTP-Passwort unbemerkt unverschlüsselt über die Leitung. Mit der Option bricht der Versand in diesem Fall mit einer Fehlermeldung ab.

Das Zertifikat des Mailservers wird immer geprüft. Nur für einen internen Mailserver mit selbstsigniertem Zertifikat kann die Prüfung mit `&verify_peer=false` (bzw. `?verify_peer=false`) abgeschaltet werden – für Mailserver im Internet nicht empfehlenswert.

Die `.env` sollte nur für den Server-Benutzer lesbar sein:

```bash
chmod 600 .env
```

---

## Testen

Vorschau im Terminal, ohne etwas zu versenden (funktioniert auch ohne Mail-Einstellungen):

```bash
php bin/weekly-report.php --dry-run
```

Testmail nur an die eigene Adresse, statt an alle Empfänger:

```bash
php bin/weekly-report.php --to=ich@example.org
```

Fehlt eine Einstellung oder ist sie ungültig, nennt das Skript alle Probleme auf einmal und beendet sich mit Exit-Code 1.

---

## Automatischer Versand (Cron)

Der Bericht wird per Cron-Job auf dem Server verschickt, z. B. jeden **Montag um 7:00 Uhr**:

```bash
crontab -e
```

```cron
0 7 * * 1  cd /var/www/sanlager && php bin/weekly-report.php >> database/weekly-report.log 2>&1
```

Den Pfad `/var/www/sanlager` durch das tatsächliche Projektverzeichnis ersetzen. Der Cron-Job muss unter einem Benutzer laufen, der die Datenbank und die `.env` lesen darf, typischerweise derselbe wie der Webserver.

Die Entnahmen umfassen immer die sieben vollen Tage vor dem Lauf; bei einem Lauf am Montag also Montag bis Sonntag der Vorwoche.

---

## Hinweise

* Für den Versand braucht der Server Zugang zum Mailserver (ausgehend Port 587 bzw. 465). Die Weboberfläche selbst funktioniert weiterhin ohne Internetzugriff.
* Das Skript liegt unter `bin/` und damit außerhalb von `public/`. Es ist nicht über den Webserver aufrufbar und verweigert den Start, wenn es nicht über die Kommandozeile läuft.
* Die Mindestbestände werden je Artikel und Lagerort auf der Artikelseite gepflegt. Nur Lagerorte mit eingetragenem Mindestbestand erscheinen im Bericht.
