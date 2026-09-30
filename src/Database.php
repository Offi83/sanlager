<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Öffnet die SQLite-Verbindung und wendet beim Start automatisch alle
 * noch fehlenden Migrationen aus `database/migrations/` an.
 *
 * Es gibt bewusst kein separates Migrations-Kommando: Jeder Seitenaufruf
 * prüft und aktualisiert das Schema selbst (siehe migrate()).
 */
class Database
{
    private PDO $connection;
    private string $migrationsPath;

    public function __construct(string $database)
    {
        $this->migrationsPath = dirname(__DIR__) . '/database/migrations';

        $this->connection = new PDO('sqlite:' . $database);

        $this->connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->connection->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $this->connection->exec('PRAGMA foreign_keys = ON');

        /*
         * Mehrere Geräte buchen gleichzeitig (Pi-Terminal, Handys):
         *
         * - IMMEDIATE: Transaktionen holen sich die Schreibsperre schon
         *   beim Beginn. Eine Buchung kann so zwischen Bestandsprüfung und
         *   Speichern nicht von einer anderen überholt werden (sonst wäre
         *   z. B. das letzte Stück doppelt ausbuchbar, siehe
         *   StockRepository::move()).
         * - Timeout: Ist die Datenbank gerade gesperrt, bis zu 10 Sekunden
         *   warten statt sofort mit "database is locked" abzubrechen.
         */
        $this->connection->setAttribute(
            \Pdo\Sqlite::ATTR_TRANSACTION_MODE,
            \Pdo\Sqlite::TRANSACTION_MODE_IMMEDIATE
        );

        $this->connection->setAttribute(PDO::ATTR_TIMEOUT, 10);

        $this->migrate();
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    /**
     * Schneller Weg für den Normalfall "Schema ist aktuell".
     *
     * Die Nummer der neuesten angewendeten Migration steht in der
     * SQLite-Kopfzeile (`PRAGMA user_version`). Stimmt sie mit der
     * neuesten Migrationsdatei überein, ist nichts zu tun – das kostet
     * pro Seitenaufruf nur ein Verzeichnislisting und eine Abfrage,
     * statt jede Migration einzeln gegen schema_migrations zu prüfen.
     *
     * Nur wenn eine neuere Migrationsdatei vorliegt (oder die Datenbank
     * neu bzw. noch ohne user_version ist), läuft der vollständige
     * Abgleich in runMigrations().
     */
    private function migrate(): void
    {
        $migrationFiles = $this->migrationFiles();

        $latestVersion = $migrationFiles === []
            ? 0
            : max(array_keys($migrationFiles));

        $currentVersion = (int) $this->connection
            ->query('PRAGMA user_version')
            ->fetchColumn();

        if ($currentVersion === $latestVersion) {
            return;
        }

        $this->runMigrations($migrationFiles);

        /*
         * PRAGMA erlaubt keine gebundenen Parameter; $latestVersion ist
         * eine per max() ermittelte Ganzzahl.
         */
        $this->connection->exec('PRAGMA user_version = ' . $latestVersion);
    }

    /**
     * Liefert alle Migrationsdateien, indiziert nach ihrer Nummer
     * (`008_xyz.sql` => 8) und aufsteigend sortiert.
     *
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException(
                'Migrations-Verzeichnis nicht gefunden: ' . $this->migrationsPath
            );
        }

        $paths = glob($this->migrationsPath . '/*.sql');

        if ($paths === false) {
            throw new RuntimeException(
                'Migration-Dateien konnten nicht gelesen werden.'
            );
        }

        $migrationFiles = [];

        foreach ($paths as $path) {
            $migration = basename($path);

            if (!preg_match('/^(\d+)_/', $migration, $matches)) {
                throw new RuntimeException(
                    'Migration ohne Nummer im Dateinamen: ' . $migration
                );
            }

            $version = (int) $matches[1];

            if (isset($migrationFiles[$version])) {
                throw new RuntimeException(
                    'Migrationsnummer doppelt vergeben: ' . $migration
                    . ' und ' . basename($migrationFiles[$version])
                );
            }

            $migrationFiles[$version] = $path;
        }

        ksort($migrationFiles);

        return $migrationFiles;
    }

    /**
     * Führt alle noch nicht angewendeten Migrationen aus.
     */
    private function runMigrations(array $migrationFiles): void
    {
        /*
         * Migration-Historie anlegen.
         */
        $this->connection->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )
        ');

        foreach ($migrationFiles as $migrationFile) {
            $migration = basename($migrationFile);

            $sql = file_get_contents($migrationFile);

            if ($sql === false) {
                throw new RuntimeException(
                    'Migration konnte nicht gelesen werden: ' . $migration
                );
            }

            /*
             * Prüfen und Ausführen unter derselben Schreibsperre (IMMEDIATE,
             * siehe Konstruktor): Öffnen nach einem Update zwei Geräte die
             * Seite gleichzeitig, wartet das zweite hier und sieht danach,
             * dass das erste die Migration schon angewendet hat – statt sie
             * noch einmal auszuführen.
             */
            $this->connection->beginTransaction();

            try {
                if ($this->isMigrationRecorded($migration)) {
                    $this->connection->commit();
                    continue;
                }

                $this->connection->exec($sql);

                $this->recordMigration($migration);

                $this->connection->commit();
            } catch (\Throwable $exception) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                throw new RuntimeException(
                    'Migration fehlgeschlagen: ' . $migration
                    . PHP_EOL
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    }

    /**
     * Prüft, ob eine Migration bereits in schema_migrations registriert ist.
     */
    private function isMigrationRecorded(string $migration): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM schema_migrations
             WHERE migration = :migration
             LIMIT 1'
        );

        $statement->execute([
            'migration' => $migration,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Trägt eine Migration als erfolgreich angewendet ein.
     */
    private function recordMigration(string $migration): void
    {
        $statement = $this->connection->prepare(
            'INSERT OR IGNORE INTO schema_migrations
                (migration, applied_at)
             VALUES
                (:migration, CURRENT_TIMESTAMP)'
        );

        $statement->execute([
            'migration' => $migration,
        ]);
    }
}
