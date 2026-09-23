<?php

/*
|--------------------------------------------------------------------------
| Gemeinsamer Start für Weboberfläche und Kommandozeile
|--------------------------------------------------------------------------
|
| Lädt Composer-Autoload und .env, setzt die Zeitzone und öffnet die
| Datenbank (inkl. automatischer Migrationen). Wird von public/index.php
| und den Skripten unter bin/ eingebunden, damit beide garantiert
| dieselbe Konfiguration verwenden.
|
| Liefert die PDO-Verbindung zurück.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LagerApp\Database;

Dotenv::createImmutable(__DIR__)->safeLoad();

/*
 * Zeitzone für alle Datumsberechnungen ("heute", MHD-Ablauf, heutige
 * Ausbuchungen). Ohne diese Einstellung nutzt PHP je nach php.ini UTC,
 * wodurch rund um Mitternacht der falsche Tag als "heute" gelten würde.
 */
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Berlin');

$dbFile = __DIR__ . '/' . ($_ENV['DB_DATABASE'] ?? 'database/database.sqlite');

if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0775, true);
}

return (new Database($dbFile))->connection();
