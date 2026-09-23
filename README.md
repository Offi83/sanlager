# SanLager

[![Tests](https://github.com/Offi83/sanlager/actions/workflows/tests.yml/badge.svg)](https://github.com/Offi83/sanlager/actions/workflows/tests.yml)

## Digitale Lagerverwaltung für Sanitätsmaterial

**SanLager** ist eine schlanke Webanwendung zur Verwaltung von Sanitätsmaterial z.B. bei einer Hiorg.

Die Anwendung wurde speziell für den praktischen Einsatz im Sanitätslager entwickelt. Im Mittelpunkt stehen eine **einfache Bedienung**, eine **schnelle Bestandsübersicht** und die **Verwaltung von Mindesthaltbarkeitsdaten**.

Das System soll jederzeit einen schnellen Überblick über den aktuellen Bestand ermöglichen. Hierzu wird das Material in Kisten gelagert die entsprechent mit einem QR-Code-Etikett das in der App erzeugt werden kann beschriftet.
![SanLager Schema](images/schema_lager.png)

Es gibt eine an 800x480px angepasste Ansicht für den Raspberry Pi Touchscreen.

---

## Funktionen

* Übersicht des aktuellen Lagerbestands
* Verwaltung von Sanitätsmaterial
* Anzeige von Mindesthaltbarkeitsdaten
* Erkennung abgelaufener Artikel
* Verwaltung von Artikelstammdaten
* Verwaltung und Sortierung von Kategorien
* Verwaltung und Sortierung von Lagerorten
* Buchen: Ausbuchen oder Umbuchen an einen anderen Lagerort per Kamera, Hand-Barcodescanner oder manueller Eingabe – immer das älteste MHD zuerst
* gut sichtbare Anzeige der Buchungsrichtung (rot = Ausbuchen, blau = Umbuchen); die gewählte Richtung bleibt für weitere Buchungen erhalten
* „Scanner starten“ erscheint nur auf Geräten mit Kamera
* Aus-, Umbuchungen und Entsorgungen des Tages mit einem Klick rückgängig machen – als Gegenbuchung, die Historie bleibt erhalten
* abgelaufene Chargen mit einem Klick entsorgen (MHD-Übersicht, Artikel- und Lagerort-Seite)
* Wöchentlicher Bericht per E-Mail: abgelaufenes und bald ablaufendes Material, Unterschreitung der Mindestbestände, Entnahmen der Woche
* Artikelseite: Einlagern, Ausbuchen und Umbuchen einer bestimmten Charge und Menge – mit Anzeige, was am gewählten Lagerort liegt
* QR-Code je Artikel und Etikettendruck
* einfache und übersichtliche Bedienung
* optimiert für die Nutzung per Touchscreen
* funktioniert vollständig ohne Internetzugriff (auch der Kamera-Scanner ist lokal eingebunden)
* lokale SQLite-Datenbank
* Webzugriff über Browser
* Raspberry-Pi-Terminal für das Lager (optional)

## ToDo

* Unterstützung Labelprinter
* Test mit QR-Code-Scanner
* Ausbuchen mit Anzahl >1
* Einbuchen via Scan & Anzahl
* Weitere Umsetzung der Lagerorte (Artikel pro Lagerort, ablaufende MHD pro Lagerort, etc.)
* Inventurfunktion

---

## Screenshots

Die Screenshots zeigen Beispieldaten im Format des Raspberry-Pi-Displays (800×480). Sie werden mit `./script/screenshots.sh` erzeugt (siehe [Entwicklung](docs/11-entwicklung.md#screenshots-aktualisieren)).

*Buchen – hier eine Umbuchung vom Hauptlager in einen Rucksack. Auf Geräten mit Kamera erscheint zusätzlich „Scanner starten“.*
![Buchen](images/buchen.png)

*Heute ausgebuchte Artikel*
![Heute ausgebuchte Artikel](images/ausgebucht.png)

*MHD-Übersicht – abgelaufenes und bald ablaufendes Material je Lagerort*
![MHD-Übersicht](images/mhd.png)

*Artikelübersicht – rot: unter Mindestbestand*
![Artikelübersicht](images/artikel.png)

*Artikel mit QR-Code, Bestand und Mindestbestand je Lagerort*
![Artikeldetail](images/artikel-detail.png)

*Kategorien anlegen, sortieren und anpassen*
![Kategorien](images/kategorien.png)

*Lagerorte anlegen, sortieren und deaktivieren*
![Lagerorte](images/lagerorte.png)

*Wochenbericht per E-Mail*
![Wochenbericht](images/wochenbericht.png)

---

## Aufbau

SanLager besteht aus einer webbasierten Anwendung und optional einem fest installierten Lagerterminal.

```text
┌──────────────────────────────┐
│          SanLager            │
│        Webanwendung          │
│                              │
│        PHP / SQLite          │
└──────────────┬───────────────┘
               │
               │ HTTPS
               │
┌──────────────▼───────────────┐
│       Raspberry Pi           │
│                              │
│       7" Touchscreen         │
│       Chromium Kiosk         │
│                              │
│      SanLager Terminal       │
└──────────────────────────────┘
```

---

## Dokumentation

Die technische Dokumentation ist in einzelne Bereiche aufgeteilt.

### 📖 Projekt

* Vorstellung und Konzept des SanLagers
* Aufbau und Funktionsweise
* verwendete Technologien

### 🛠️ Installation

➡️ **[Installation](docs/05-installation.md)**

### 🗄️ Datenbank

➡️ **[Dokumentation der Datenbankstruktur und der einzelnen Tabellen.](docs/10-datenbank.md)**

### 🖥️ Raspberry Pi

Einrichtung des Raspberry Pi als festes SanLager-Terminal:

* Raspberry Pi OS
* Touchscreen
* Display-Drehung
* Chromium im Kiosk-Modus
* automatische Anmeldung
* Autostart
* Fehlerbehebung

➡️ **[Raspberry-Pi-Terminal einrichten](docs/90-raspberry-pi.md)**

### 📧 Wochenbericht

Einrichtung des wöchentlichen Berichts per E-Mail (SMTP-Zugang, Empfänger, Cron-Job):

➡️ **[Wochenbericht einrichten](docs/12-wochenbericht.md)**

### 💾 Datensicherung

Tägliche, geprüfte Sicherung der Datenbank per Cron, Aufbewahrung und Wiederherstellung:

➡️ **[Datensicherung einrichten](docs/13-datensicherung.md)**

### 🔧 Entwicklung

**[Dokumentation zur Projektstruktur, Entwicklung und Bereitstellung der Anwendung.](docs/11-entwicklung.md)**

---

## Technologie

SanLager verwendet bewusst einfache und robuste Technologien:

* **PHP**
* **SQLite**
* **HTML / CSS**
* **JavaScript**
* **Raspberry Pi OS**
* **Chromium**
* **Git / GitHub**

---

## Lizenz

SanLager ist freie Software unter der **GNU General Public License v3.0** (GPL-3.0-only), siehe [LICENSE](LICENSE).

Mitgelieferte und verwendete Fremdbestandteile (u. a. der QR-Code-Scanner html5-qrcode unter Apache-2.0 und Symbole aus Lucide unter ISC) sind mit ihren Lizenzen in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) aufgeführt; die Lizenztexte liegen unter [licenses/](licenses/).

---

## Ziel

SanLager soll keine komplexe Warenwirtschaft sein.

Die Anwendung konzentriert sich auf das, was im Sanitätslager tatsächlich benötigt wird:

> **Was ist vorhanden, was läuft ab und was muss nachbeschafft werden?**

Die Bedienung soll dabei so einfach sein, dass sie auch direkt im Lager über einen Touchscreen genutzt werden kann.

---

## Status

SanLager befindet sich in der laufenden Entwicklung und wird schrittweise um weitere Funktionen ergänzt.

