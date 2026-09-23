<?php

namespace LagerApp;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Datensicherung der SQLite-Datenbank, aufgerufen per Cron über
 * bin/backup.php.
 *
 * Die Kopie entsteht mit `VACUUM INTO`: SQLite schreibt dabei einen in
 * sich konsistenten Stand, auch während die Anwendung gerade genutzt wird
 * (anders als ein einfaches Kopieren der Datei). Jede Sicherung wird
 * anschließend geöffnet und geprüft; alte Sicherungen werden nach
 * `$keepDays` Tagen gelöscht, die neueste bleibt immer erhalten.
 */
final class Backup
{
    /**
     * Nur Dateien mit diesem Muster werden angelegt und beim Aufräumen
     * gelöscht – andere Dateien im Sicherungsordner bleiben unberührt.
     */
    private const PATTERN = '/^sanlager-(\d{4}-\d{2}-\d{2})_(\d{6})\.sqlite$/';

    public function __construct(
        private PDO $db,
        private string $directory,
        private int $keepDays = 30
    ) {
        if ($keepDays < 1) {
            throw new RuntimeException('Die Aufbewahrungsdauer muss mindestens 1 Tag betragen.');
        }
    }

    /**
     * Legt eine geprüfte Sicherung an und liefert ihren Pfad.
     *
     * @throws RuntimeException wenn Ordner, Sicherung oder Prüfung scheitern
     */
    public function create(?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true)) {
            throw new RuntimeException('Sicherungsordner kann nicht angelegt werden: ' . $this->directory);
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException('Sicherungsordner ist nicht beschreibbar: ' . $this->directory);
        }

        $target = rtrim($this->directory, '/') . '/sanlager-' . $now->format('Y-m-d_His') . '.sqlite';

        if (file_exists($target)) {
            throw new RuntimeException('Sicherung existiert bereits: ' . $target);
        }

        try {
            $statement = $this->db->prepare('VACUUM INTO :target');
            $statement->execute(['target' => $target]);

            // Enthält den gesamten Lagerbestand – nur für den Besitzer lesbar.
            chmod($target, 0600);

            $this->verify($target);
        } catch (Throwable $exception) {
            if (file_exists($target)) {
                unlink($target);
            }

            throw new RuntimeException(
                'Sicherung fehlgeschlagen: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $target;
    }

    /**
     * Öffnet eine Sicherung und prüft sie mit `PRAGMA integrity_check`.
     *
     * @throws RuntimeException wenn die Datei beschädigt oder unvollständig ist
     */
    public function verify(string $path): void
    {
        $copy = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $result = $copy->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            throw new RuntimeException('Integritätsprüfung fehlgeschlagen: ' . $result);
        }

        $tables = (int) $copy
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('articles', 'stock_movements')")
            ->fetchColumn();

        if ($tables !== 2) {
            throw new RuntimeException('Die Sicherung enthält nicht die erwarteten Tabellen.');
        }
    }

    /**
     * Löscht Sicherungen, die älter als `$keepDays` Tage sind. Die neueste
     * Sicherung bleibt immer erhalten, auch wenn sie älter ist (z. B. wenn
     * die Sicherung länger nicht lief).
     *
     * @return string[] gelöschte Dateien
     */
    public function prune(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $limit = $now->modify('-' . $this->keepDays . ' days');

        $backups = $this->list();
        array_pop($backups);

        $deleted = [];

        foreach ($backups as $path => $createdAt) {
            if ($createdAt < $limit && unlink($path)) {
                $deleted[] = $path;
            }
        }

        return $deleted;
    }

    /**
     * Vorhandene Sicherungen, älteste zuerst.
     *
     * @return array<string, DateTimeImmutable> Pfad => Zeitpunkt der Sicherung
     */
    public function list(): array
    {
        $backups = [];

        foreach (glob(rtrim($this->directory, '/') . '/sanlager-*.sqlite') ?: [] as $path) {
            if (preg_match(self::PATTERN, basename($path), $matches)) {
                $backups[$path] = DateTimeImmutable::createFromFormat('!Y-m-d His', $matches[1] . ' ' . $matches[2]);
            }
        }

        asort($backups);

        return $backups;
    }
}
