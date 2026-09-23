<?php

namespace LagerApp\Tests;

use DateTimeImmutable;
use LagerApp\ArticleRepository;
use LagerApp\Backup;
use LagerApp\Database;
use LagerApp\StockRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupTest extends TestCase
{
    private string $dir;
    private PDO $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sanlager-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->db = (new Database($this->dir . '/live.sqlite'))->connection();

        $articleId = (new ArticleRepository($this->db))->create('A-1', 'Mullbinde', '', 'Stück', null);
        (new StockRepository($this->db))->move($articleId, 1, 7, 'receipt');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->dir);
    }

    private function backup(int $keepDays = 30): Backup
    {
        return new Backup($this->db, $this->dir . '/backups', $keepDays);
    }

    public function testCreatesVerifiedCopyWithAllData(): void
    {
        $path = $this->backup()->create(new DateTimeImmutable('2026-09-23 02:00:05'));

        $this->assertSame($this->dir . '/backups/sanlager-2026-09-23_020005.sqlite', $path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $copy = new PDO('sqlite:' . $path);
        $this->assertSame(7, (int) $copy->query('SELECT SUM(quantity) FROM stock_movements')->fetchColumn());
        $this->assertSame('Mullbinde', $copy->query('SELECT name FROM articles')->fetchColumn());
    }

    public function testBackupWorksWhileAnotherConnectionIsWriting(): void
    {
        $other = (new Database($this->dir . '/live.sqlite'))->connection();
        $other->beginTransaction();
        $other->exec("UPDATE articles SET name = 'noch nicht gespeichert'");

        $this->db->setAttribute(PDO::ATTR_TIMEOUT, 0);

        try {
            $path = $this->backup()->create();
        } finally {
            $other->rollBack();
        }

        // Nur der gespeicherte Stand landet in der Sicherung.
        $copy = new PDO('sqlite:' . $path);
        $this->assertSame('Mullbinde', $copy->query('SELECT name FROM articles')->fetchColumn());
    }

    public function testPruneDeletesOldBackupsButKeepsNewestAndForeignFiles(): void
    {
        $backup = $this->backup(30);
        $now = new DateTimeImmutable('2026-09-23 02:00:00');

        $old = $backup->create($now->modify('-40 days'));
        $recent = $backup->create($now->modify('-10 days'));
        $current = $backup->create($now);
        file_put_contents($this->dir . '/backups/notiz.txt', 'nicht löschen');
        file_put_contents($this->dir . '/backups/sanlager-kaputt.sqlite', 'fremd');

        $this->assertSame([$old], $backup->prune($now));

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($current);
        $this->assertFileExists($this->dir . '/backups/notiz.txt');
        $this->assertFileExists($this->dir . '/backups/sanlager-kaputt.sqlite');
    }

    public function testNewestBackupIsKeptEvenIfOld(): void
    {
        $backup = $this->backup(30);
        $only = $backup->create(new DateTimeImmutable('2025-01-01 02:00:00'));

        $this->assertSame([], $backup->prune(new DateTimeImmutable('2026-09-23')));
        $this->assertFileExists($only);
    }

    public function testVerifyRejectsDamagedFile(): void
    {
        $path = $this->backup()->create();
        file_put_contents($path, str_repeat("\0", 4096));

        $this->expectException(\Throwable::class);

        $this->backup()->verify($path);
    }

    public function testFailsClearlyForUnusableDirectory(): void
    {
        file_put_contents($this->dir . '/datei', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sicherungsordner');

        (new Backup($this->db, $this->dir . '/datei/unterordner'))->create();
    }
}
