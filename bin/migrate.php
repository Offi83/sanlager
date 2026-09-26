<?php

/*
|--------------------------------------------------------------------------
| SanLager – Migrationen sofort ausführen
|--------------------------------------------------------------------------
|
| Normalerweise zieht SanLager fehlende Migrationen beim nächsten
| Seitenaufruf selbst nach (siehe src/Database.php). script/pull.sh ruft
| dieses Skript direkt nach einem Update auf, damit ein Fehler dabei
| sofort im Terminal steht statt erst im Browser.
|
|   php bin/migrate.php
|
| Gibt es noch keine Datenbank, wird hier bewusst keine angelegt: Sie
| gehörte sonst dem Benutzer im Terminal statt dem Webserver.
|
| Liegt außerhalb von public/ und ist nicht über den Webserver aufrufbar.
|--------------------------------------------------------------------------
*/

use Dotenv\Dotenv;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile aufrufbar.');
}

$root = dirname(__DIR__);

try {
    require_once $root . '/vendor/autoload.php';

    Dotenv::createImmutable($root)->safeLoad();

    // Wie in bootstrap.php.
    $dbFile = $_ENV['DB_DATABASE'] ?? 'database/database.sqlite';

    if (!str_starts_with($dbFile, '/')) {
        $dbFile = $root . '/' . $dbFile;
    }

    if (!is_file($dbFile)) {
        echo "Noch keine Datenbank ($dbFile) – sie wird beim ersten Seitenaufruf angelegt.\n";
        exit(0);
    }

    $db = require $root . '/bootstrap.php';

    printf(
        "Datenbank ist aktuell (Migration %03d).\n",
        (int) $db->query('PRAGMA user_version')->fetchColumn()
    );

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fehler: ' . $exception->getMessage() . "\n");
    exit(1);
}
