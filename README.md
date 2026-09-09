# Raspberry Pi Terminal

Einrichtung eines Raspberry Pi 4B mit offiziellem 7" Raspberry Touch Screen als Darstellung & Buchungsterminal direkt im Lager.

Der Raspberry Pi startet nach dem Einschalten automatisch die grafische Oberfläche, dreht das angeschlossene Raspberry Pi 7" Touch Display um 180° und öffnet die SanLager-Webanwendung in Chromium im Kiosk-Modus.

Die durch `.htaccess` geschützte HTTP-Basic-Authentication wird automatisch über das Chrome DevTools Protocol (CDP) durchgeführt.

## Hardware

* Raspberry Pi 4
* offizielles Raspberry Pi 7" Touch Display
* Gehäuse für Pi und Display
* microSD-Karte

## Software

Aktuell getestet mit:

* Raspberry Pi OS 64-bit
* Debian 13 (trixie)
* labwc
* Chromium 149
* Python 3.13
* `python3-websocket`

---

## 1. Raspberry Pi OS installieren

Mit dem **Raspberry Pi Imager** installieren:

**Raspberry Pi OS (64-bit) mit Desktop**

Bei der Installation konfigurieren:

* (W)LAN
* SSH aktivieren

Nach dem ersten Start per SSH anmelden:

```bash
ssh bereitschaft@192.168.x.x
```

---

## 2. System aktualisieren

```bash
sudo apt update
sudo apt full-upgrade -y
sudo reboot
```

---

## 4. Python-WebSocket-Unterstützung installieren

WebSocket-Unterstützung installieren:

```bash
sudo apt install -y python3-websocket
```

---

## 5. SanLager-Konfiguration anlegen

Verzeichnis erstellen:

```bash
mkdir -p ~/.config/sanlager
chmod 700 ~/.config/sanlager
```

### Zugangsdaten

Die SanLager-Webseite verwendet HTTP Basic Authentication.

Die Zugangsdaten werden **nicht** in die URL und **nicht** in das Python-Programm geschrieben.

Datei erstellen:

```bash
nano ~/.config/sanlager/auth
```

Inhalt:

```text
BENUTZERNAME=<BENUTZERNAME>
PASSWORT=<PASSWORT>
```

Die echten Zugangsdaten entsprechend einsetzen.

Datei schützen:

```bash
chmod 600 ~/.config/sanlager/auth
```

---

## 6. Chromium Helper installieren

Der Python-Helper startet Chromium und übernimmt die HTTP-Basic-Authentication über das Chrome DevTools Protocol.

Datei erstellen:

```bash
nano ~/.config/sanlager/start-chromium.py
```

Inhalt:

```python
#!/usr/bin/env python3

import json
import os
import subprocess
import time
import urllib.request
import traceback
from datetime import datetime

import websocket

CONFIG = os.path.expanduser("~/.config/sanlager/auth")
LOGFILE = os.path.expanduser("~/.config/sanlager/start-chromium.log")

CHROMIUM = "/usr/bin/chromium"
URL = "https://sanlager.meinedomain.de"
PORT = 9222
PROFILE = os.path.expanduser("~/.config/chromium-sanlager")


def log(message):
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    with open(LOGFILE, "a", encoding="utf-8") as f:
        f.write(f"[{timestamp}] {message}\n")
        f.flush()


def load_credentials():
    credentials = {}

    with open(CONFIG, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()

            if not line or line.startswith("#") or "=" not in line:
                continue

            key, value = line.split("=", 1)
            credentials[key.strip()] = value.strip()

    return credentials["BENUTZERNAME"], credentials["PASSWORT"]


def wait_for_debugger():
    log("Warte auf Chromium DevTools...")

    for i in range(30):
        try:
            with urllib.request.urlopen(
                f"http://127.0.0.1:{PORT}/json/version",
                timeout=1
            ) as response:
                data = json.load(response)

            log(f"DevTools erreichbar nach {i + 1} Sekunden.")
            log(f"Browser: {data.get('Browser', 'unbekannt')}")
            return data

        except Exception as e:
            log(f"DevTools noch nicht erreichbar: {type(e).__name__}: {e}")
            time.sleep(1)

    raise RuntimeError("Chromium DevTools konnte nicht erreicht werden.")


def send_command(ws, method, params, command_id):
    log(f"CDP → {method}")

    ws.send(json.dumps({
        "id": command_id,
        "method": method,
        "params": params
    }))


def main():
    log("========================================")
    log("SanLager Chromium Helper gestartet")

    try:
        username, password = load_credentials()
        log("Zugangsdaten geladen.")

        os.makedirs(PROFILE, mode=0o700, exist_ok=True)

        log("Starte Chromium...")

        chromium = subprocess.Popen([
            CHROMIUM,
            "--ozone-platform=wayland",
            "--enable-features=UseOzonePlatform",
            "--kiosk",
            "--noerrdialogs",
            "--disable-infobars",
            "--disable-session-crashed-bubble",
            "--start-fullscreen",
            "--password-store=basic",
            "--remote-allow-origins=http://127.0.0.1:9222",
            f"--user-data-dir={PROFILE}",
            "--remote-debugging-address=127.0.0.1",
            f"--remote-debugging-port={PORT}",
            "about:blank",
        ])

        log(f"Chromium PID: {chromium.pid}")

        wait_for_debugger()

        with urllib.request.urlopen(
            f"http://127.0.0.1:{PORT}/json",
            timeout=2
        ) as response:
            targets = json.load(response)

        log(f"Targets gefunden: {len(targets)}")

        page = next(
            target for target in targets
            if target.get("type") == "page"
        )

        log(f"WebSocket URL: {page['webSocketDebuggerUrl']}")

        ws = websocket.create_connection(
            page["webSocketDebuggerUrl"],
            timeout=60,
            origin=f"http://127.0.0.1:{PORT}"
        )

        log("CDP WebSocket verbunden.")

        send_command(
            ws,
            "Fetch.enable",
            {
                "handleAuthRequests": True
            },
            1
        )

        time.sleep(0.2)

        log("Fetch/Auth-Handling aktiviert.")

        log(f"Navigiere zu {URL}")

        ws.send(json.dumps({
            "id": 2,
            "method": "Page.navigate",
            "params": {
                "url": URL
            }
        }))

        log("Page.navigate gesendet.")

        next_command_id = 100

        while True:
            try:
                message = json.loads(ws.recv())
            except Exception as e:
                log(f"CDP Empfang beendet: {type(e).__name__}: {e}")
                break

            method = message.get("method")

            if method:
                log(f"CDP Event: {method}")

            if method == "Fetch.authRequired":
                params = message["params"]

                request_id = params["requestId"]
                origin = params.get("origin", "")
                scheme = params.get("scheme", "")
                realm = params.get("realm", "")

                log(
                    f"HTTP-Auth angefordert: "
                    f"origin={origin}, scheme={scheme}, realm={realm}"
                )

                ws.send(json.dumps({
                    "id": next_command_id,
                    "method": "Fetch.continueWithAuth",
                    "params": {
                        "requestId": request_id,
                        "authChallengeResponse": {
                            "response": "ProvideCredentials",
                            "username": username,
                            "password": password
                        }
                    }
                }))

                log("HTTP-Basic-Auth beantwortet.")

                next_command_id += 1

            elif method == "Fetch.requestPaused":
                request_id = message["params"]["requestId"]

                ws.send(json.dumps({
                    "id": next_command_id,
                    "method": "Fetch.continueRequest",
                    "params": {
                        "requestId": request_id
                    }
                }))

                next_command_id += 1

            elif method == "Page.loadEventFired":
                log("Seite vollständig geladen.")

            elif message.get("id") == 2:
                log(f"Page.navigate Antwort: {message}")

            if chromium.poll() is not None:
                log(f"Chromium beendet, Exit-Code: {chromium.returncode}")
                break

    except Exception as e:
        log(f"FEHLER: {type(e).__name__}: {e}")
        log(traceback.format_exc())
        log("Chromium wird bei Fehler NICHT beendet.")


if __name__ == "__main__":
    main()
```

Datei ausführbar machen:

```bash
chmod 700 ~/.config/sanlager/start-chromium.py
```

Syntax prüfen:

```bash
python3 -m py_compile ~/.config/sanlager/start-chromium.py
```

Bei erfolgreicher Prüfung gibt es keine Ausgabe.

---

## 7. Autostart konfigurieren

Datei:

```text
~/.config/labwc/autostart
```

Inhalt:

```bash
wlr-randr --output DSI-1 --transform 180

sleep 3

python3 ~/.config/sanlager/start-chromium.py
```

Damit passiert beim Start der grafischen Oberfläche automatisch:

1. Display um 180° drehen
2. kurz warten
3. Chromium starten
4. SanLager öffnen
5. HTTP-Basic-Authentication durchführen

---

## 8. Autostart testen

Raspberry Pi neu starten:

```bash
sudo reboot
```

Nach dem Neustart sollte keine Eingabe erforderlich sein.

Der komplette Ablauf:

```text
Raspberry Pi startet
        │
        ▼
      WLAN
        │
        ▼
  Grafische Oberfläche
        │
        ▼
   Display 180°
        │
        ▼
     Chromium
        │
        ▼
    SanLager
        │
        ▼
 HTTP Basic Auth
        │
        ▼
SanLager betriebsbereit
```

---

## 9. Log

Der Chromium-Helper schreibt ein Log:

```text
~/.config/sanlager/start-chromium.log
```

---

## 10. Verzeichnisstruktur

Die relevante Konfiguration:

```text
~/.config/
├── chromium-sanlager/
│   └── ...
├── labwc/
│   ├── autostart
│   └── rc.xml
└── sanlager/
    ├── auth
    ├── start-chromium.py
    └── start-chromium.log
```

| Datei                | Funktion                                          |
| -------------------- | ------------------------------------------------- |
| `auth`               | Zugangsdaten für HTTP Basic Auth                  |
| `start-chromium.py`  | Chromium-Kiosk und automatische Authentifizierung |
| `start-chromium.log` | Start- und Fehlerprotokoll                        |
| `labwc/autostart`    | Display-Rotation und Chromium-Start               |
| `labwc/rc.xml`       | Touchscreen-Konfiguration                         |
| `chromium-sanlager/` | separates Chromium-Profil                         |
