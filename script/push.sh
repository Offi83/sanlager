#!/bin/bash

if [ -z "$1" ]; then
    echo "Fehler: Bitte eine Beschreibung der Änderungen angeben."
    echo "Aufruf: ./push.sh \"Beschreibung der Änderungen\""
    exit 1
fi

cd "$(dirname "$0")/.." || exit 1

echo "=== Git Status ==="
git status

if [ -z "$(git status --porcelain)" ]; then
    echo "Keine Änderungen vorhanden."
    exit 0
fi

echo
echo "=== Beispieldatenbank erzeugen ==="
# Bei jedem Push frisch aus script/demo-data.php, damit sie zum Code passt
# (Migrationen, Beispieldaten). Die MHDs sind relativ zum heutigen Tag.
# Erst nach der Prüfung oben – sonst gäbe es jeden Tag eine "Änderung".

EXAMPLE_DB="beispieldaten/beispieldaten.sqlite"
EXAMPLE_TMP="$(mktemp -d)"

php script/demo-data.php "$EXAMPLE_TMP/beispieldaten.sqlite" || { rm -rf "$EXAMPLE_TMP"; exit 1; }
mkdir -p beispieldaten
mv "$EXAMPLE_TMP/beispieldaten.sqlite" "$EXAMPLE_DB"
rm -rf "$EXAMPLE_TMP"

echo
echo "=== Änderungen hinzufügen ==="
git add -A

echo
echo "=== Commit ==="
git commit -m "$1" || exit 1

echo
echo "=== Push zu GitHub ==="
git push origin main || exit 1

echo
echo "=== Fertig ==="
echo "Änderungen wurden erfolgreich zu GitHub gepusht."
