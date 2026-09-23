<?php

namespace LagerApp\Tests;

use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\Database;
use LagerApp\LocationRepository;
use LagerApp\StockRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests der Bestandslogik gegen eine frische In-Memory-Datenbank, auf
 * die alle Migrationen angewendet werden (inkl. Lagerort "Hauptlager").
 */
class StockRepositoryTest extends TestCase
{
    private PDO $db;
    private StockRepository $stock;
    private BatchRepository $batches;
    private int $articleId;
    private int $mainId;
    private int $boxId;

    protected function setUp(): void
    {
        $this->db = (new Database(':memory:'))->connection();

        $this->stock = new StockRepository($this->db);
        $this->batches = new BatchRepository($this->db);

        $locations = new LocationRepository($this->db);

        $this->mainId = (int) $locations->findByName('Hauptlager')['id'];
        $this->boxId = $locations->create('Kiste 1', '');

        $this->articleId = (new ArticleRepository($this->db))->create(
            'A-001',
            'Mullbinde',
            '',
            'Stück',
            null
        );
    }

    private function day(string $modifier): string
    {
        return date('Y-m-d', strtotime($modifier));
    }

    private function receive(int $quantity, ?string $expiry, ?int $locationId = null): ?int
    {
        $batchId = $expiry !== null
            ? $this->batches->findOrCreate($this->articleId, $expiry)
            : null;

        $this->stock->move(
            $this->articleId,
            $locationId ?? $this->mainId,
            $quantity,
            'receipt',
            null,
            $batchId
        );

        return $batchId;
    }

    public function testIssueOldestTakesOldestExpiryFirst(): void
    {
        $this->receive(2, null);
        $this->receive(2, $this->day('+2 years'));
        $oldBatch = $this->receive(1, $this->day('+1 year'));

        $result = $this->stock->issueOldest($this->articleId, $this->mainId);

        $this->assertSame($oldBatch, $result['batch_id']);
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $oldBatch));
        $this->assertSame(4, $this->stock->getTotalStock($this->articleId));
    }

    public function testIssueOldestTakesStockWithoutExpiryLast(): void
    {
        $this->receive(1, null);
        $this->receive(1, $this->day('+1 year'));

        $this->stock->issueOldest($this->articleId, $this->mainId);
        $result = $this->stock->issueOldest($this->articleId, $this->mainId);

        $this->assertNull($result['batch_id']);
    }

    public function testIssueOldestIncludesExpiredBatch(): void
    {
        $expired = $this->receive(1, $this->day('-1 day'));
        $this->receive(1, $this->day('+1 year'));

        $result = $this->stock->issueOldest($this->articleId, $this->mainId);

        $this->assertSame($expired, $result['batch_id']);
        $this->assertSame('expiry-expired', expiryInfo($result['expiry_date'])['class']);
    }

    public function testIssueWithoutStockFails(): void
    {
        $this->receive(1, null, $this->boxId);

        $this->expectException(RuntimeException::class);

        $this->stock->issueOldest($this->articleId, $this->mainId);
    }

    public function testMoveRejectsIssueBeyondBatchStock(): void
    {
        $batch = $this->receive(2, $this->day('+1 year'));

        $this->expectException(RuntimeException::class);

        $this->stock->move($this->articleId, $this->mainId, 3, 'issue', null, $batch);
    }

    public function testMoveRejectsZeroAndUnknownType(): void
    {
        try {
            $this->stock->move($this->articleId, $this->mainId, 0, 'receipt');
            $this->fail('Menge 0 wurde akzeptiert.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);

        $this->stock->move($this->articleId, $this->mainId, 1, 'gift');
    }

    public function testTransferOldestKeepsBatch(): void
    {
        $batch = $this->receive(3, $this->day('+1 year'));

        $result = $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);

        $this->assertSame($batch, $result['batch_id']);
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $batch));
        $this->assertSame(3, $this->stock->getTotalStock($this->articleId));
    }

    public function testTransferOldestRejectsSameLocation(): void
    {
        $this->receive(1, null);

        $this->expectException(RuntimeException::class);

        $this->stock->transferOldest($this->articleId, $this->mainId, $this->mainId);
    }

    public function testTransferAllStockMovesEverything(): void
    {
        $batch = $this->receive(4, $this->day('+1 year'), $this->boxId);
        $this->receive(2, null, $this->boxId);

        $moved = $this->stock->transferAllStock($this->boxId, $this->mainId);

        $this->assertSame(6, $moved);
        $this->assertFalse($this->stock->locationHasStock($this->boxId));
        $this->assertSame(4, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId));
    }

    public function testTransferAllStockFromEmptyLocationMovesNothing(): void
    {
        $this->assertSame(0, $this->stock->transferAllStock($this->boxId, $this->mainId));
    }

    public function testExpiredStockIsNotCountedAsUsable(): void
    {
        $this->receive(3, $this->day('-1 day'));
        $this->receive(2, $this->day('+1 year'));
        $this->receive(1, null);
        $this->receive(4, $this->day('today'));

        // Ein MHD von heute gilt noch als verwendbar.
        $this->assertSame(7, $this->stock->getTotalStock($this->articleId));
        $this->assertSame(3, $this->stock->getExpiredStock($this->articleId));

        $main = array_values(array_filter(
            $this->stock->getStockForArticle($this->articleId),
            fn (array $row): bool => (int) $row['location_id'] === $this->mainId
        ))[0];

        $this->assertSame(10, (int) $main['quantity']);
        $this->assertSame(7, (int) $main['usable_quantity']);
    }

    public function testLowStockConsidersOnlyUsableStockAtMonitoredLocation(): void
    {
        $this->receive(3, $this->day('+1 year'));
        $this->receive(5, $this->day('-1 day'));
        $this->receive(10, null, $this->boxId);

        $this->assertFalse($this->stock->hasLowStockAtAnyLocation($this->articleId));

        $this->stock->saveMinimums($this->articleId, [$this->mainId => 3]);
        $this->assertFalse($this->stock->hasLowStockAtAnyLocation($this->articleId));

        $this->stock->saveMinimums($this->articleId, [$this->mainId => 4]);
        $this->assertTrue($this->stock->hasLowStockAtAnyLocation($this->articleId));

        $this->stock->saveMinimums($this->articleId, [$this->mainId => null]);
        $this->assertFalse($this->stock->hasLowStockAtAnyLocation($this->articleId));
    }

    public function testTodayIssuesCountOnlyIssuesNotTransfers(): void
    {
        $this->receive(5, null);

        $this->stock->issueOldest($this->articleId, $this->mainId);
        $this->stock->issueOldest($this->articleId, $this->mainId);
        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);

        $this->assertSame(2, $this->stock->getTodayIssueCount());

        $issues = $this->stock->getTodayIssues();

        $this->assertCount(1, $issues);
        $this->assertSame(2, (int) $issues[0]['quantity']);
        $this->assertSame('Hauptlager', $issues[0]['location_name']);
    }

    public function testTodayIssuesIgnoreYesterday(): void
    {
        $this->receive(5, null);
        $this->stock->issueOldest($this->articleId, $this->mainId);

        // Buchung auf gestern (lokale Zeit, gespeichert in UTC) zurückdatieren.
        $yesterday = (new \DateTimeImmutable('yesterday 23:30'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->db->prepare(
            'UPDATE stock_movements SET created_at = :created_at WHERE movement_type = \'issue\''
        )->execute(['created_at' => $yesterday]);

        $this->assertSame(0, $this->stock->getTodayIssueCount());
        $this->assertSame([], $this->stock->getTodayIssues());
    }

    public function testExpiringBatchesWithinWindow(): void
    {
        $this->receive(1, $this->day('-10 days'));
        $this->receive(1, $this->day('+30 days'));
        $this->receive(1, $this->day('+200 days'));
        $this->receive(1, null);

        $rows = $this->stock->getExpiringBatches(90);

        $this->assertSame(
            [$this->day('-10 days'), $this->day('+30 days')],
            array_column($rows, 'expiry_date')
        );
    }

    public function testBatchFindOrCreateReusesBatchAndRejectsInvalidDate(): void
    {
        $first = $this->batches->findOrCreate($this->articleId, '2027-12-31');

        $this->assertSame($first, $this->batches->findOrCreate($this->articleId, '2027-12-31'));
        $this->assertNull($this->batches->findOrCreate($this->articleId, ''));

        $this->expectException(RuntimeException::class);

        $this->batches->findOrCreate($this->articleId, '31.12.2027');
    }
}
