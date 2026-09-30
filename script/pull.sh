#!/bin/bash

# Aktualisiert den Server auf den Stand von GitHub (origin/main):
#   1. Neuen Stand holen, Änderungen und Testergebnis zeigen, Rückfrage
#   2. Datensicherung (bin/backup.php)
#   3. Code aktualisieren
#   4. PHP-Abhängigkeiten passend zu composer.lock installieren –
#      scheitert das, zurück auf den vorherigen Stand
#   5. Migrationen sofort ausführen (bin/migrate.php)

cd "$(dirname "$0")/.." || exit 1

yes_answer() {
    case "$1" in
        [jJ]|[jJ][aA]|[yY]|[yY][eE][sS]) return 0 ;;
        *) return 1 ;;
    esac
}

# Vor allem anderen prüfen: Ohne Composer ließe sich der neue Stand
# nicht vollständig installieren.
if ! command -v composer > /dev/null; then
    echo "Fehler: composer nicht gefunden. Bitte zuerst installieren."
    exit 1
fi

echo "=== Neuen Stand von GitHub holen ==="
# --tags: auch Release-Tags, die erst nach dem letzten Update auf GitHub
# angelegt wurden – die Fußzeile zeigt die Version aus dem Tag.
# Ändert noch nichts am laufenden Code.
git fetch --tags origin || exit 1

OLD_COMMIT="$(git rev-parse HEAD)"
NEW_COMMIT="$(git rev-parse origin/main)"

echo
if [ "$OLD_COMMIT" = "$NEW_COMMIT" ]; then
    echo "Der Code ist bereits auf dem neuesten Stand."
else
    echo "Neu seit dem letzten Update:"
    git log --format='  %cd  %s' --date=format:'%d.%m.%Y' "$OLD_COMMIT..$NEW_COMMIT" | cut -c1-110
fi

echo
echo "=== Testergebnis auf GitHub ==="
php script/ci-status.php "$NEW_COMMIT"
CI_STATUS=$?

echo
echo "Lokale Änderungen an getrackten Dateien gehen beim Update verloren."
if [ "$CI_STATUS" -eq 1 ]; then
    read -r -p "Die Tests sind ROT. Trotzdem aktualisieren? [j/N] " answer
else
    read -r -p "Jetzt aktualisieren? [j/N] " answer
fi

if ! yes_answer "$answer"; then
    echo "Abgebrochen, nichts geändert."
    exit 0
fi

echo
echo "=== Datensicherung ==="
# Beim nächsten Seitenaufruf laufen ggf. Migrationen, die die Datenbank
# umbauen – deshalb vorher sichern.
if [ -f vendor/autoload.php ]; then
    if ! php bin/backup.php; then
        echo
        read -r -p "Sicherung fehlgeschlagen. Trotzdem ohne Sicherung weitermachen? [j/N] " answer

        if ! yes_answer "$answer"; then
            echo "Abgebrochen, nichts geändert."
            exit 1
        fi
    fi
else
    echo "Übersprungen (Abhängigkeiten noch nicht installiert)."
fi

echo
echo "=== Lokalen Code aktualisieren ==="
git reset -q --hard "$NEW_COMMIT" || exit 1

echo
echo "=== Abhängigkeiten installieren ==="
if ! composer install --no-dev --optimize-autoloader --no-interaction; then
    echo
    echo "Fehler: composer install ist fehlgeschlagen."
    echo "Zurück auf den vorherigen Stand, damit SanLager weiterläuft ..."

    if git reset -q --hard "$OLD_COMMIT" && composer install --no-dev --optimize-autoloader --no-interaction; then
        echo
        echo "SanLager läuft wieder mit dem vorherigen Stand. Das Update bitte später"
        echo "noch einmal versuchen (z. B. kein Internet für composer?)."
    else
        echo
        echo "Fehler: Auch der vorherige Stand ließ sich nicht installieren."
        echo "SanLager läuft erst wieder, wenn composer install klappt."
    fi

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
