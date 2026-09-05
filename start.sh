#!/bin/zsh

cd "$(dirname "$0")"

echo ""
echo "================================"
echo " DRK Lager-App"
echo "================================"
echo ""
echo "PHP:     $(php -v | head -n 1)"
echo "SQLite:  $(sqlite3 --version | cut -d' ' -f1)"
echo ""
echo "Server:"
echo "http://localhost:8080"
echo ""
echo "Beenden mit CTRL+C"
echo ""

php -S localhost:8080 -t public
