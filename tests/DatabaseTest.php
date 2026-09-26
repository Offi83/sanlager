<?php

namespace LagerApp\Tests;

use LagerApp\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sanlager-test-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function latestMigrationVersion(): int
    {
        $versions = array_map(
            static fn (string $path): int => (int) basename($path),
            glob(__DIR__ . '/../database/migrations/*.sql')
        );

        return max($versions);
    }

    private function userVersion(PDO $db): int
    {
        return (int) $db->query('PRAGMA user_version')->fetchColumn();
    }

    private function recordedMigrations(PDO $db): int
    {
        return (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    }

    public function testUnitsMigrationTakesOverFreeTextUnits(): void
    {
        /*
         * Datenbank im Stand vor Migration 011 (Einheit als Freitext am
         * Artikel) nachbauen, dann wie beim Update die App starten.
         */
        $old = new PDO('sqlite:' . $this->file);
        $old->exec('CREATE TABLE schema_migrations (migration TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');

        foreach (glob(__DIR__ . '/../database/migrations/*.sql') as $path) {
            if ((int) basename($path) >= 11) {
                continue;
            }

            $old->exec(file_get_contents($path));
            $old->exec("INSERT INTO schema_migrations VALUES ('" . basename($path) . "', CURRENT_TIMESTAMP)");
        }

        $old->exec('PRAGMA user_version = 10');
        $old->exec("INSERT INTO articles (article_number, name, unit) VALUES
            ('a', 'Mullbinde', 'Stück'), ('b', 'Pflaster', 'Rolle'),
            ('c', 'Kompressen', ' Packung '), ('d', 'Tupfer', 'Beutel'), ('e', 'Alt', '')");
        $old = null;

        $db = (new Database($this->file))->connection();

        $this->assertSame(
            ['Stück' => 'Stück', 'Beutel' => 'Beutel', 'Packung' => 'Packungen', 'Rolle' => 'Rollen'],
            $db->query('SELECT name, plural FROM units ORDER BY sort_order')->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        $this->assertSame(
            ['Alt' => 'Stück', 'Kompressen' => 'Packung', 'Mullbinde' => 'Stück', 'Pflaster' => 'Rolle', 'Tupfer' => 'Beutel'],
            $db->query('SELECT a.name, u.name FROM articles a JOIN units u ON u.id = a.unit_id ORDER BY a.name')->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        // Die alte Freitext-Spalte ist weg.
        $columns = array_column($db->query('PRAGMA table_info(articles)')->fetchAll(), 'name');
        $this->assertNotContains('unit', $columns);
        $this->assertSame([], $db->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testFreshDatabaseIsMigratedAndVersioned(): void
    {
        $db = (new Database($this->file))->connection();

        $this->assertSame($this->latestMigrationVersion(), $this->userVersion($db));
        $this->assertSame(
            count(glob(__DIR__ . '/../database/migrations/*.sql')),
            $this->recordedMigrations($db)
        );
    }

    public function testUpToDateDatabaseSkipsMigrationCheck(): void
    {
        $db = (new Database($this->file))->connection();
        $count = $this->recordedMigrations($db);

        // Eintrag einer (wiederholbaren) Migration entfernen: Bei aktuellem
        // user_version darf der Abgleich ihn NICHT wiederherstellen.
        $db->exec("DELETE FROM schema_migrations WHERE migration = '005_seed_default_location.sql'");
        unset($db);

        $db = (new Database($this->file))->connection();

        $this->assertSame($count - 1, $this->recordedMigrations($db));
    }

    public function testOutdatedVersionTriggersFullCheck(): void
    {
        $db = (new Database($this->file))->connection();
        $count = $this->recordedMigrations($db);

        $db->exec("DELETE FROM schema_migrations WHERE migration = '005_seed_default_location.sql'");
        $db->exec('PRAGMA user_version = 0');
        unset($db);

        $db = (new Database($this->file))->connection();

        $this->assertSame($count, $this->recordedMigrations($db));
        $this->assertSame($this->latestMigrationVersion(), $this->userVersion($db));
        $this->assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM storage_locations WHERE name = 'Hauptlager'")->fetchColumn()
        );
    }

    /**
     * Zwei Geräte öffnen die Seite nach einem Update gleichzeitig: Das
     * zweite muss nach dem Warten auf die Sperre erkennen, dass das erste
     * die Migration inzwischen angewendet hat, statt sie noch einmal
     * auszuführen (010 fügt eine Spalte hinzu – doppelt scheitert das).
     */
    public function testMigrationAppliedByOtherDeviceMeanwhileIsSkipped(): void
    {
        $db = (new Database($this->file))->connection();
        $db->exec("DELETE FROM schema_migrations WHERE migration = '010_article_has_expiry.sql'");
        $db->exec('PRAGMA user_version = 0');
        unset($db);

        // Gerät 1 migriert gerade (hält die Schreibsperre) ...
        $first = new PDO('sqlite:' . $this->file);
        $first->exec('BEGIN IMMEDIATE');

        // ... Gerät 2 ruft die Seite auf und wartet auf die Sperre.
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                'require $argv[1]; new LagerApp\Database($argv[2]);',
                __DIR__ . '/../vendor/autoload.php',
                $this->file,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        usleep(700_000);

        $first->exec(
            "INSERT INTO schema_migrations (migration, applied_at)
             VALUES ('010_article_has_expiry.sql', CURRENT_TIMESTAMP)"
        );
        $first->exec('COMMIT');

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, $output);
    }

    public function testBookingWaitsForOtherDeviceInsteadOfReadingStaleStock(): void
    {
        $first = (new Database($this->file))->connection();
        $second = (new Database($this->file))->connection();

        $this->assertSame(
            \Pdo\Sqlite::TRANSACTION_MODE_IMMEDIATE,
            $second->getAttribute(\Pdo\Sqlite::ATTR_TRANSACTION_MODE)
        );

        $articleId = (new \LagerApp\ArticleRepository($first))->create('A-1', 'Mullbinde', '', 'Stück', null);
        (new \LagerApp\StockRepository($first))->move($articleId, 1, 1, 'receipt');

        // Gerät 1 bucht gerade (offene Transaktion) ...
        $first->beginTransaction();

        // ... Gerät 2 darf den Bestand nicht schon lesen, sondern muss
        // warten; hier ohne Wartezeit, damit der Test sofort scheitert.
        $second->setAttribute(PDO::ATTR_TIMEOUT, 0);

        try {
            (new \LagerApp\StockRepository($second))->issueOldest($articleId, 1);
            $this->fail('Buchung lief trotz fremder Schreibsperre.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('locked', $exception->getMessage());
        } finally {
            $first->rollBack();
        }

        $this->assertFalse($second->inTransaction());
        $this->assertSame(1, (new \LagerApp\StockRepository($second))->getStockAtLocation($articleId, 1));
    }
}
