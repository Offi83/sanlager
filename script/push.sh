#!/bin/bash

if [ -z "$1" ]; then
    echo "Fehler: Bitte eine Beschreibung der Änderungen angeben."
    echo "Aufruf: ./push.sh \"Beschreibung der Änderungen\""
    exit 1
fi

echo "=== Git Status ==="
git status

if [ -z "$(git status --porcelain)" ]; then
    echo "Keine Änderungen vorhanden."
    exit 0
fi

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
