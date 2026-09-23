<?php

/*
|--------------------------------------------------------------------------
| SanLager – Datensicherung
|--------------------------------------------------------------------------
|
| Legt eine geprüfte Kopie der Datenbank an und löscht Sicherungen, die
| älter als BACKUP_KEEP_DAYS Tage sind (die neueste bleibt immer).
|
| Gedacht für einen täglichen Cron-Job, z. B. nachts um 2 Uhr:
|
|   0 2 * * *  cd /pfad/zu/sanlager && php bin/backup.php >> backups/backup.log 2>&1
|
| Einstellungen in der .env (siehe .env.example, docs/13-datensicherung.md):
|   BACKUP_DIR        Zielordner (Standard: backups/ im Projektordner)
|   BACKUP_KEEP_DAYS  Aufbewahrung in Tagen (Standard: 30)
|
| Liegt außerhalb von public/ und ist nicht über den Webserver aufrufbar.
|--------------------------------------------------------------------------
*/

use LagerApp\Backup;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile aufrufbar.');
}

$root = dirname(__DIR__);

try {
    $db = require $root . '/bootstrap.php';

    $directory = trim((string) ($_ENV['BACKUP_DIR'] ?? ''));

    if ($directory === '') {
        $directory = $root . '/backups';
    } elseif (!str_starts_with($directory, '/')) {
        $directory = $root . '/' . $directory;
    }

    $keepDays = trim((string) ($_ENV['BACKUP_KEEP_DAYS'] ?? '')) ?: '30';

    if (!ctype_digit($keepDays)) {
        throw new RuntimeException('BACKUP_KEEP_DAYS muss eine ganze Zahl sein.');
    }

    $backup = new Backup($db, $directory, (int) $keepDays);

    $path = $backup->create();
    $deleted = $backup->prune();

    printf(
        "[%s] Sicherung angelegt: %s (%s KB)%s\n",
        date('Y-m-d H:i'),
        $path,
        number_format(filesize($path) / 1024, 0, ',', '.'),
        $deleted ? ', ' . count($deleted) . ' alte Sicherung(en) gelöscht' : ''
    );

    exit(0);
} catch (Throwable $exception) {
    /*
     * Ausgabe auf STDERR mit Exit-Code 1, damit Cron bzw. das Log den
     * Fehler zeigt.
     */
    fwrite(STDERR, '[' . date('Y-m-d H:i') . '] Datensicherung fehlgeschlagen: ' . $exception->getMessage() . "\n");
    exit(1);
}
