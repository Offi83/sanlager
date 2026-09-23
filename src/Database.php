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
     *
     * Bei einer bereits bestehenden Datenbank wird zusätzlich geprüft,
     * ob eine Migration anhand des vorhandenen Schemas bereits durchgeführt
     * wurde. Dadurch können ältere Datenbanken auf das neue
     * schema_migrations-System übernommen werden, ohne Daten zu verlieren.
     */
    private function runMigrations(array $migrationFiles): void
    {
        /*
         * Migration-Historie anlegen.
         *
         * Ältere Datenbankstände können noch eine schema_migrations-Tabelle
         * aus einem früheren, inkompatiblen Migrationssystem enthalten
         * (z. B. mit den Spalten filename/checksum statt migration).
         * Eine solche Tabelle wird beiseitegelegt, damit die aktuelle
         * Tabellenstruktur angelegt werden kann. Bereits angewendete
         * Migrationen werden anschließend über migrationAlreadyAppliedInSchema()
         * anhand des vorhandenen Datenbankschemas wiedererkannt.
         */
        if (
            $this->hasTable('schema_migrations')
            && !$this->hasColumn('schema_migrations', 'migration')
        ) {
            $this->connection->exec(
                'ALTER TABLE schema_migrations RENAME TO schema_migrations_legacy'
            );
        }

        $this->connection->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )
        ');

        foreach ($migrationFiles as $migrationFile) {
            $migration = basename($migrationFile);

            /*
             * Bereits offiziell registrierte Migration überspringen.
             */
            if ($this->isMigrationRecorded($migration)) {
                continue;
            }

            /*
             * Prüfen, ob diese Migration bereits durch einen älteren
             * Entwicklungsstand in der Datenbank umgesetzt wurde.
             */
            if ($this->migrationAlreadyAppliedInSchema($migration)) {
                $this->recordMigration($migration);
                continue;
            }

            $sql = file_get_contents($migrationFile);

            if ($sql === false) {
                throw new RuntimeException(
                    'Migration konnte nicht gelesen werden: ' . $migration
                );
            }

            $this->connection->beginTransaction();

            try {
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

    /**
     * Erkennt Migrationen, die bereits durch einen älteren Stand
     * der Anwendung in der Datenbank umgesetzt wurden.
     */
    private function migrationAlreadyAppliedInSchema(string $migration): bool
    {
        switch ($migration) {
            case '001_initial.sql':
                return $this->hasTable('storage_locations')
                    && $this->hasTable('articles')
                    && $this->hasTable('batches')
                    && $this->hasTable('stock')
                    && $this->hasTable('stock_movements');

            case '002_batches.sql':
                return $this->hasIndex('idx_movements_batch')
                    && $this->hasIndex('idx_batches_article')
                    && $this->hasIndex('idx_batches_expiry');

            case '003_article_categories.sql':
                /*
                 * category_id ist das entscheidende Merkmal.
                 *
                 * Der Index wird bei einer bereits bestehenden Datenbank
                 * vorsichtshalber ebenfalls sichergestellt.
                 */
                if (
                    $this->hasTable('article_categories')
                    && $this->hasColumn('articles', 'category_id')
                ) {
                    $this->connection->exec(
                        'CREATE INDEX IF NOT EXISTS idx_articles_category
                         ON articles(category_id)'
                    );

                    return true;
                }

                return false;

            case '004_article_categories_details.sql':
                /*
                 * Beide Spalten müssen vorhanden sein.
                 *
                 * Damit erkennen wir den bereits vollständig ausgeführten
                 * Stand von Migration 004.
                 */
                $hasShortName = $this->hasColumn(
                    'article_categories',
                    'short_name'
                );

                $hasColor = $this->hasColumn(
                    'article_categories',
                    'color'
                );

                if ($hasShortName && $hasColor) {
                    return true;
                }

                /*
                 * Eine teilweise ausgeführte 004-Migration kann nicht
                 * automatisch erneut ausgeführt werden, weil ALTER TABLE
                 * sonst an der bereits vorhandenen Spalte scheitern würde.
                 */
                if ($hasShortName xor $hasColor) {
                    throw new RuntimeException(
                        'Migration 004 ist nur teilweise vorhanden: '
                        . 'short_name und color müssen gemeinsam vorhanden sein.'
                    );
                }

                return false;

            default:
                /*
                 * Für zukünftige Migrationen gibt es noch keine automatische
                 * Schema-Erkennung. Sie werden normal über die SQL-Datei
                 * ausgeführt.
                 */
                return false;
        }
    }

    /**
     * Prüft, ob eine Tabelle existiert.
     */
    private function hasTable(string $table): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1
             FROM sqlite_master
             WHERE type = 'table'
               AND name = :name
             LIMIT 1"
        );

        $statement->execute([
            'name' => $table,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Prüft, ob eine Spalte in einer Tabelle existiert.
     */
    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->query(
            'PRAGMA table_info("' . str_replace('"', '""', $table) . '")'
        )->fetchAll();

        foreach ($columns as $columnInfo) {
            if ($columnInfo['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft, ob ein Index existiert.
     */
    private function hasIndex(string $index): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1
             FROM sqlite_master
             WHERE type = 'index'
               AND name = :name
             LIMIT 1"
        );

        $statement->execute([
            'name' => $index,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
