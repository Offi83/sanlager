# 🏷️ Etikettendrucker (optional)

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

Lange Namen werden an Leerzeichen und nach Bindestrichen umbrochen (bis zu drei Zeilen) und dabei kleiner, aber nie kleiner als die Artikelnummer. Maße und Einheiten bleiben zusammen („10 × 10 cm“, „6 cm x 4 m“, „100 ml“, „Gr. 4“). Passt ein einzelnes Wort dann immer noch nicht, wird es nach Silben getrennt (z. B. „Blutzuckermess-streifen“). Die Silbentrennung (Bibliothek vanderlee/syllable mit den deutschen TeX-Trennmustern) läuft offline auf dem Server.

## Drucker gehört an den Server

Gedruckt wird **vom Server aus**, auf dem SanLager (PHP) läuft – nicht vom Gerät, an dem gerade jemand „Drucken“ antippt. Deshalb kommen `brother_ql` und die Einstellungen auf den Server, und der Drucker muss von dort erreichbar sein:

* **WLAN/Netzwerk:** Der Server muss den Drucker im Netz erreichen (Port 9100). Das geht immer, solange beide im selben Netz sind.
* **USB:** Der Drucker steckt am Server. Am [Raspberry-Pi-Terminal](08-raspberry-pi.md) geht USB also nur, wenn SanLager selbst auf dem Pi läuft; ist der Pi nur Anzeige für einen anderen Server, den Drucker per WLAN anbinden.

## Warum brother_ql statt Druckertreiber

Unter Linux drucken sowohl der freie CUPS-Treiber (`printer-driver-ptouch`) als auch Brothers eigene Linux-Treiber **nur schwarz**. Für **Rot/Schwarz** schickt SanLager die Etiketten deshalb über das Programm `brother_ql` aus dem Python-Paket [brother-ql-next](https://github.com/LunarEclipse363/brother_ql_next) direkt an den Drucker. CUPS wird dafür nicht gebraucht.

## Installation (Debian / Raspberry Pi OS)

Auf dem Server – PHP-Erweiterung `gd` (zeichnet die Etiketten) und Python:

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

## Anschluss per WLAN

1. Drucker ins normale WLAN bringen (nicht „Wireless Direct“, bei dem er ein eigenes Netz aufspannt) – z. B. mit Brothers Einrichtungsprogramm oder über die Weboberfläche des Druckers.
2. Im Router eine **feste IP-Adresse** für den Drucker reservieren.
3. Energiesparmodus prüfen: Meldet sich der Drucker nach einiger Zeit aus dem WLAN ab, das in den Druckereinstellungen abschalten.
4. Vom Server aus testen, ob der Drucker erreichbar ist:

   ```bash
   nc -z -v 192.168.1.50 9100
   ```

In der `.env`: `LABEL_PRINTER=tcp://192.168.1.50:9100`.

Über WLAN kann der Drucker **keinen Status zurückmelden**. SanLager meldet deshalb „an den Drucker gesendet“; ob wirklich gedruckt wurde (Rolle leer, Deckel offen, falsche Rolle), ist nur am Gerät zu sehen. Nur wenn der Drucker gar nicht erreichbar ist, gibt es eine Fehlermeldung.

## Anschluss per USB

1. Am QL-810W(c) **„Editor Lite“ ausschalten**: die Editor-Lite-Taste gedrückt halten, bis die grüne Lampe ausgeht. Mit Editor Lite meldet sich der Drucker als USB-Speicher und nimmt keine Druckaufträge an.
2. Drucker am Server anschließen und prüfen, ob `/dev/usb/lp0` erscheint (`ls /dev/usb/`).
3. Dem Webserver erlauben, auf den Drucker zu schreiben, danach Apache neu starten:

   ```bash
   sudo usermod -aG lp www-data
   sudo systemctl restart apache2
   ```

In der `.env`: `LABEL_PRINTER=file:///dev/usb/lp0`.

Per USB meldet der Drucker zurück, ob gedruckt wurde. SanLager zeigt dann „gedruckt“ bzw. Fehler wie „Keine Rolle eingelegt“, „Deckel offen“ oder „Falsche Rolle eingelegt“.

## Einstellungen (.env)

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

## Erster Test

Vor dem ersten Druck aus SanLager `brother_ql` einmal von Hand aufrufen (mit einem beliebigen Bild):

```bash
/opt/brother-ql/bin/brother_ql -b network -m QL-810W -p tcp://192.168.1.50:9100 print -l 62red --red bild.png
```

Bei USB `-b linux_kernel -p file:///dev/usb/lp0`, und zwar als Webserver-Benutzer (`sudo -u www-data …`), damit auch die Berechtigung geprüft wird. Danach in SanLager ein Etikett drucken und prüfen: Ist das Rot sauber, lässt sich der QR-Code mit dem Hand-Scanner lesen, passt die Länge auf die Box? Die tatsächliche Länge kann um einige Millimeter abweichen, dann `LABEL_LENGTH_MM` anpassen.

Fehlermeldungen von `brother_ql` stehen vollständig im Fehlerprotokoll des Webservers (z. B. `/var/log/apache2/sanlager-error.log`).
