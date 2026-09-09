#!/bin/bash

echo "=== GitHub-Update ==="
echo
echo "ACHTUNG:"
echo "Der lokale Code wird auf den Stand von origin/main zurückgesetzt."
echo "Lokale Änderungen an getrackten Dateien können dabei verloren gehen."
echo
read -r -p "Lokalen Code wirklich mit GitHub überschreiben? [j/N] " answer

case "$answer" in
    [jJ]|[jJ][aA]|[yY]|[yY][eE][sS])
        ;;
    *)
        echo "Abgebrochen."
        exit 0
        ;;
esac

echo
echo "=== Änderungen von GitHub holen ==="
git fetch origin || exit 1

echo
echo "=== Lokalen Code aktualisieren ==="
git reset --hard origin/main || exit 1

echo
echo "=== Status ==="
git status --short --ignored

echo
echo "=== Fertig ==="
echo "Server wurde auf origin/main aktualisiert."
