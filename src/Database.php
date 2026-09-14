<?php

namespace LagerApp;

use PDO;
use RuntimeException;

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

        $this->runMigrations();
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    /**
     * Führt alle noch nicht angewendeten Migrationen aus.
     *
     * Bei einer bereits bestehenden Datenbank wird zusätzlich geprüft,
     * ob eine Migration anhand des vorhandenen Schemas bereits durchgeführt
     * wurde. Dadurch können ältere Datenbanken auf das neue
     * schema_migrations-System übernommen werden, ohne Daten zu verlieren.
     */
    private function runMigrations(): void
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException(
                'Migrations-Verzeichnis nicht gefunden: ' . $this->migrationsPath
            );
        }

        /*
         * Migration-Historie anlegen.
         */
        $this->connection->exec('
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )
        ');

        $migrationFiles = glob($this->migrationsPath . '/*.sql');

        if ($migrationFiles === false) {
            throw new RuntimeException(
                'Migration-Dateien konnten nicht gelesen werden.'
            );
        }

        sort($migrationFiles, SORT_STRING);

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
