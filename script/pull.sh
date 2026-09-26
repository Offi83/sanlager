#!/bin/bash

# Aktualisiert den Server auf den Stand von GitHub (origin/main):
#   1. Datensicherung (bin/backup.php)
#   2. Code holen
#   3. PHP-Abhängigkeiten passend zu composer.lock installieren
#   4. Migrationen sofort ausführen (bin/migrate.php)

cd "$(dirname "$0")/.." || exit 1

echo "=== GitHub-Update ==="
echo
echo "ACHTUNG:"
echo "Der lokale Code wird auf den Stand von origin/main zurückgesetzt."
echo "Lokale Änderungen an getrackten Dateien können dabei verloren gehen."
echo "Vorher bitte auf GitHub prüfen, ob der letzte Testlauf grün ist."
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

# Vor dem Zurücksetzen prüfen: Ohne Composer ließe sich der neue Stand
# nicht vollständig installieren.
if ! command -v composer > /dev/null; then
    echo "Fehler: composer nicht gefunden. Bitte zuerst installieren."
    exit 1
fi

echo
echo "=== Datensicherung ==="
# Beim nächsten Seitenaufruf laufen ggf. Migrationen, die die Datenbank
# umbauen – deshalb vorher sichern.
if [ -f vendor/autoload.php ]; then
    if ! php bin/backup.php; then
        echo
        read -r -p "Sicherung fehlgeschlagen. Trotzdem ohne Sicherung weitermachen? [j/N] " answer

        case "$answer" in
            [jJ]|[jJ][aA]|[yY]|[yY][eE][sS])
                ;;
            *)
                echo "Abgebrochen, nichts geändert."
                exit 1
                ;;
        esac
    fi
else
    echo "Übersprungen (Abhängigkeiten noch nicht installiert)."
fi

echo
echo "=== Änderungen von GitHub holen ==="
git fetch origin || exit 1

echo
echo "=== Lokalen Code aktualisieren ==="
git reset --hard origin/main || exit 1

echo
echo "=== Abhängigkeiten installieren ==="
if ! composer install --no-dev --optimize-autoloader --no-interaction; then
    echo
    echo "Fehler: composer install ist fehlgeschlagen. Der Code ist bereits"
    echo "aktualisiert – SanLager läuft erst wieder, wenn das klappt."
    exit 1
fi

echo
echo "=== Datenbank aktualisieren ==="
if ! php bin/migrate.php; then
    echo
    echo "Hinweis: Die Migration wird beim nächsten Seitenaufruf erneut versucht."
    echo "Scheitert sie dort auch, steht der Grund im Browser bzw. im PHP-Fehlerprotokoll."
    exit 1
fi

echo
echo "=== Status ==="
git status --short --ignored

echo
echo "=== Fertig ==="
echo "Server wurde auf origin/main aktualisiert."
