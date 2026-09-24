#!/bin/bash

# ---------------------------------------------------------------------
# SanLager – Screenshots für README/Doku neu erzeugen
#
# Legt eine Demo-Datenbank an (script/demo-data.php), startet dafür
# einen PHP-Entwicklungsserver und nimmt die Seiten mit Chrome/Chromium
# (headless) im Format des Pi-Displays auf: 800×480, doppelte Auflösung.
# Die produktive Datenbank wird nicht verwendet.
#
# Aufruf:  ./script/screenshots.sh
# Chrome-Pfad bei Bedarf über CHROME=/pfad/zu/chrome vorgeben.
# ---------------------------------------------------------------------

set -euo pipefail

cd "$(dirname "$0")/.."

PORT=8097
BASE="http://localhost:${PORT}"
OUT="images"
DB="database/screenshots-demo.sqlite"
TMP="$(mktemp -d)"

if [ -z "${CHROME:-}" ]; then
    for candidate in \
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
        "/Applications/Chromium.app/Contents/MacOS/Chromium" \
        "$(command -v chromium 2>/dev/null || true)" \
        "$(command -v chromium-browser 2>/dev/null || true)" \
        "$(command -v google-chrome 2>/dev/null || true)"; do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then
            CHROME="$candidate"
            break
        fi
    done
fi

if [ -z "${CHROME:-}" ]; then
    echo "Chrome/Chromium nicht gefunden. Pfad über CHROME=... angeben." >&2
    exit 1
fi

cleanup() {
    if [ -n "${SERVER_PID:-}" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    rm -rf "$TMP" "$DB"
}

trap cleanup EXIT

rm -f "$DB"
php script/demo-data.php "$DB"

DB_DATABASE="$DB" php -d variables_order=EGPCS -S "localhost:${PORT}" -t public >/dev/null 2>&1 &
SERVER_PID=$!
sleep 1

# Nimmt eine URL auf: shot <datei> <url> [breite] [höhe]
# Standard ist das Pi-Display (800×480). Chrome beendet sich im
# Headless-Modus je nach System nicht zuverlässig selbst – daher warten,
# bis die Datei geschrieben ist, und Chrome dann beenden.
shot() {
    local file="$1" url="$2" width="${3:-800}" height="${4:-480}"

    rm -f "$OUT/$file"

    "$CHROME" \
        --headless=new \
        --disable-gpu \
        --hide-scrollbars \
        --no-first-run \
        --no-default-browser-check \
        --use-mock-keychain \
        --password-store=basic \
        --user-data-dir="$TMP/chrome" \
        --window-size="${width},${height}" \
        --force-device-scale-factor=2 \
        --virtual-time-budget=2000 \
        --screenshot="$OUT/$file" \
        "$url" >/dev/null 2>&1 &

    local pid=$!

    for _ in $(seq 1 60); do
        [ -s "$OUT/$file" ] && break
        sleep 0.5
    done

    sleep 0.5
    kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true

    if [ ! -s "$OUT/$file" ]; then
        echo "Screenshot fehlgeschlagen: $file" >&2
        exit 1
    fi

    echo "  $OUT/$file"
}

id_of() {
    sqlite3 "$DB" "SELECT id FROM $1 WHERE $2 LIMIT 1"
}

MAIN_ID=$(id_of storage_locations "name = 'Hauptlager'")
BAG_ID=$(id_of storage_locations "name = 'Rucksack 3'")
ARTICLE_ID=$(id_of articles "article_number = 'verb-vp-m'")

echo "Screenshots:"

# Buchen: echte Umbuchung ausführen und die Seite danach aufnehmen
# (zeigt beibehaltene Auswahl und Richtungsanzeige). Origin wie vom
# Browser, sonst lehnt der CSRF-Schutz die Buchung ab. Die
# Erfolgsmeldung fehlt im Bild: Sie liegt in der Session von curl.
REDIRECT=$(curl -s -o /dev/null -w '%{redirect_url}' \
    -H "Origin: ${BASE}" \
    -d "action=issue&article_number=verb-mullbinde-8&source=${MAIN_ID}&target=${BAG_ID}" \
    "${BASE}/?page=issue")

# Pi-Terminal ohne Kamera, daher ohne "Scanner starten". Eine Aufnahme
# mit Kamera ist headless nicht möglich: Chrome beantwortet dort die
# Geräteabfrage (enumerateDevices) nicht, auch nicht mit Fake-Kamera.
shot buchen.png "${REDIRECT:-${BASE}/?page=issue}"

shot ausgebucht.png "${BASE}/?page=today_issues"
shot mhd.png "${BASE}/?page=expiry"
shot auffuellen.png "${BASE}/?page=restock"
shot artikel.png "${BASE}/?page=articles"

shot artikel-detail.png "${BASE}/?page=article&id=${ARTICLE_ID}"

# Breiter aufgenommen, damit die Cards nebeneinander stehen
# (bei 800 px brechen sie untereinander um).
shot kategorien.png "${BASE}/?page=categories" 1024 640
shot lagerorte.png "${BASE}/?page=locations" 1024 640

# Wochenbericht (HTML-Mail), Entnahmen inkl. heute.
DB_DATABASE="$DB" php -d variables_order=EGPCS -r '
    $db = require "bootstrap.php";
    $report = new LagerApp\WeeklyReport(new LagerApp\StockReports($db));
    file_put_contents($argv[1], $report->renderHtml(
        $report->build(new DateTimeImmutable("tomorrow")),
        "https://lager.example.org"
    ));
' "$TMP/wochenbericht.html"

shot wochenbericht.png "file://$TMP/wochenbericht.html" 800 1060

echo "Fertig."
