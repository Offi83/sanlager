#!/bin/bash

# Schreibt den Entwicklungsstand nach VERSION (nicht in Git), den SanLager
# unten in der Fußzeile zeigt (appVersion() in src/helpers.php):
#   - Release: der Tag des aktuellen Commits, z. B. v0.5.0
#   - Zwischenstand: Datum und Uhrzeit des Commits (ISO 8601)
# Aufgerufen von script/pull.sh und start.sh.

cd "$(dirname "$0")/.." || exit 1

if tag=$(git describe --tags --exact-match HEAD 2> /dev/null); then
    echo "$tag" > VERSION
elif date=$(git log -1 --format=%cI 2> /dev/null); then
    echo "$date" > VERSION
else
    rm -f VERSION
    exit 1
fi
