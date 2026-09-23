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
