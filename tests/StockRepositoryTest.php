<?php

namespace LagerApp\Tests;

use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\Database;
use LagerApp\LocationRepository;
use LagerApp\StockReports;
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
    private StockReports $reports;
    private BatchRepository $batches;
    private int $articleId;
    private int $mainId;
    private int $boxId;

    protected function setUp(): void
    {
        $this->db = (new Database(':memory:'))->connection();

        $this->stock = new StockRepository($this->db);

        $this->reports = new StockReports($this->db);
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

        $this->assertSame(2, $this->reports->getTodayIssueCount());

        $issues = $this->reports->getTodayIssues();

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

        $this->assertSame(0, $this->reports->getTodayIssueCount());
        $this->assertSame([], $this->reports->getTodayIssues());
    }

    public function testExpiringBatchesWithinWindow(): void
    {
        $this->receive(1, $this->day('-10 days'));
        $this->receive(1, $this->day('+30 days'));
        $this->receive(1, $this->day('+200 days'));
        $this->receive(1, null);

        $rows = $this->reports->getExpiringBatches(90);

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

    public function testReverseTodayIssueBooksBackAndNetsTodayIssues(): void
    {
        $batch = $this->receive(5, $this->day('+1 year'));

        for ($i = 0; $i < 3; $i++) {
            $this->stock->issueOldest($this->articleId, $this->mainId);
        }

        $this->stock->reverseTodayIssue($this->articleId, $batch, $this->mainId, 1);

        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertSame(2, $this->reports->getTodayIssueCount());
        $this->assertSame(2, (int) $this->reports->getTodayIssues()[0]['quantity']);

        // Die Historie bleibt erhalten: nichts gelöscht, eine Gegenbuchung mehr.
        $types = $this->db->query('SELECT movement_type FROM stock_movements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['receipt', 'issue', 'issue', 'issue', 'issue_reversal'], $types);

        $this->stock->reverseTodayIssue($this->articleId, $batch, $this->mainId, 2);

        $this->assertSame([], $this->reports->getTodayIssues());
        $this->assertSame(0, $this->reports->getTodayIssueCount());
    }

    public function testReverseTodayIssueWithoutExpiryAndLimits(): void
    {
        $this->receive(2, null);
        $this->stock->issueOldest($this->articleId, $this->mainId);

        try {
            $this->stock->reverseTodayIssue($this->articleId, null, $this->mainId, 2);
            $this->fail('Mehr zurückgebucht als ausgebucht.');
        } catch (RuntimeException) {
        }

        $this->stock->reverseTodayIssue($this->articleId, null, $this->mainId, 1);
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId));

        // Umbuchungen lassen sich hierüber nicht "zurücknehmen".
        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);

        $this->expectException(RuntimeException::class);

        $this->stock->reverseTodayIssue($this->articleId, null, $this->mainId, 1);
    }

    public function testDisposeExpiredBatchRemovesItCompletely(): void
    {
        $expired = $this->receive(4, $this->day('-1 day'));
        $this->receive(2, $this->day('+1 year'));
        $this->receive(3, $this->day('-1 day'), $this->boxId);

        $this->assertSame(4, $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId));

        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));
        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $expired));
        $this->assertSame(2, $this->stock->getTotalStock($this->articleId));

        // Entsorgen ist kein Verbrauch.
        $this->assertSame(0, $this->reports->getTodayIssueCount());
        $this->assertSame([], $this->reports->getIssuesBetween(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow')));

        $this->expectException(RuntimeException::class);

        $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId);
    }

    public function testDisposeRejectsBatchThatIsNotExpired(): void
    {
        $fresh = $this->receive(2, $this->day('today'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nur abgelaufene');

        $this->stock->disposeExpiredBatch($this->articleId, $fresh, $this->mainId);
    }

    public function testDisposeRejectsBatchOfOtherArticle(): void
    {
        $other = (new ArticleRepository($this->db))->create('A-999', 'Andere', '', 'Stück', null);
        $expired = $this->batches->findOrCreate($other, $this->day('-1 day'));

        $this->expectException(RuntimeException::class);

        $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId);
    }

    public function testReverseTodayTransferMovesBackAndNets(): void
    {
        $batch = $this->receive(5, $this->day('+1 year'));

        for ($i = 0; $i < 3; $i++) {
            $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        }

        $transfers = $this->reports->getTodayTransfers();
        $this->assertCount(1, $transfers);
        $this->assertSame(3, (int) $transfers[0]['quantity']);
        $this->assertSame('Hauptlager', $transfers[0]['from_location_name']);
        $this->assertSame('Kiste 1', $transfers[0]['to_location_name']);

        $this->stock->reverseTodayTransfer($this->articleId, $batch, $this->mainId, $this->boxId, 2);

        $this->assertSame(4, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $batch));
        $this->assertSame(1, (int) $this->reports->getTodayTransfers()[0]['quantity']);

        // Mehr als umgebucht geht nicht.
        try {
            $this->stock->reverseTodayTransfer($this->articleId, $batch, $this->mainId, $this->boxId, 2);
            $this->fail('Mehr zurückgenommen als umgebucht.');
        } catch (RuntimeException) {
        }

        $this->stock->reverseTodayTransfer($this->articleId, $batch, $this->mainId, $this->boxId, 1);
        $this->assertSame([], $this->reports->getTodayTransfers());
        $this->assertSame(0, $this->reports->getTodayIssueCount());
    }

    public function testRealBackTransferIsNotTreatedAsUndo(): void
    {
        $this->receive(2, null);

        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        $this->stock->transferOldest($this->articleId, $this->boxId, $this->mainId);

        $directions = array_map(
            static fn (array $row): string => $row['from_location_name'] . '>' . $row['to_location_name'],
            $this->reports->getTodayTransfers()
        );

        sort($directions);

        $this->assertSame(['Hauptlager>Kiste 1', 'Kiste 1>Hauptlager'], $directions);
    }

    public function testReverseTransferFailsWhenTargetStockIsGone(): void
    {
        $this->receive(1, null);
        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        $this->stock->issueOldest($this->articleId, $this->boxId);

        $this->expectException(RuntimeException::class);

        $this->stock->reverseTodayTransfer($this->articleId, null, $this->mainId, $this->boxId, 1);
    }

    public function testTransferAllStockCanBeReversedPerArticle(): void
    {
        $batch = $this->receive(4, $this->day('+1 year'), $this->boxId);
        $this->receive(2, null, $this->boxId);

        $this->stock->transferAllStock($this->boxId, $this->mainId);

        $this->assertCount(2, $this->reports->getTodayTransfers());

        $this->stock->reverseTodayTransfer($this->articleId, $batch, $this->boxId, $this->mainId, 4);

        $this->assertSame(4, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $batch));
        $this->assertCount(1, $this->reports->getTodayTransfers());
    }

    public function testReverseTodayDisposal(): void
    {
        $expired = $this->receive(3, $this->day('-1 day'));

        $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId);

        $disposals = $this->reports->getTodayDisposals();
        $this->assertCount(1, $disposals);
        $this->assertSame(3, (int) $disposals[0]['quantity']);

        $this->stock->reverseTodayDisposal($this->articleId, $expired, $this->mainId, 3);

        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));
        $this->assertSame([], $this->reports->getTodayDisposals());

        $this->expectException(RuntimeException::class);

        $this->stock->reverseTodayDisposal($this->articleId, $expired, $this->mainId, 1);
    }

    public function testMinimumAtDeactivatedLocationIsIgnored(): void
    {
        $this->receive(5, null);
        $this->stock->saveMinimums($this->articleId, [$this->mainId => 2, $this->boxId => 3]);

        $this->assertTrue($this->stock->hasLowStockAtAnyLocation($this->articleId));

        // Kiste wird aufgelöst: leer, also deaktivierbar – ihr Mindestbestand
        // bleibt gespeichert, darf aber nicht mehr zählen.
        (new LocationRepository($this->db))->deactivate($this->boxId);

        $this->assertFalse($this->stock->hasLowStockAtAnyLocation($this->articleId));
        $this->assertFalse($this->stock->getStockSummaries()[$this->articleId]['is_low']);
        $this->assertSame([], $this->reports->getLowStockItems());
    }

    public function testArticleStockIsListedInLocationSortOrder(): void
    {
        $locations = new LocationRepository($this->db);
        $aId = $locations->create('A-Rucksack', '');
        $locations->reorder([$this->boxId, $aId, $this->mainId]);

        $this->assertSame(
            ['Kiste 1', 'A-Rucksack', 'Hauptlager'],
            array_column($this->stock->getStockForArticle($this->articleId), 'location_name')
        );

        $batch = $this->receive(1, $this->day('+1 year'));
        $this->stock->move($this->articleId, $this->boxId, 1, 'receipt', null, $batch);

        $this->assertSame(
            ['Kiste 1', 'Hauptlager'],
            array_column($this->stock->getStockByBatchAndLocation($this->articleId), 'location_name')
        );
    }

    public function testTransferHalvesShareTransferId(): void
    {
        $this->receive(3, null);

        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        $this->stock->reverseTodayTransfer($this->articleId, null, $this->mainId, $this->boxId, 1);

        $pairs = $this->db->query(
            "SELECT transfer_id, GROUP_CONCAT(movement_type) AS types, SUM(quantity) AS net
             FROM stock_movements WHERE movement_type LIKE 'transfer%'
             GROUP BY transfer_id ORDER BY transfer_id"
        )->fetchAll();

        $this->assertCount(3, $pairs);

        foreach ($pairs as $pair) {
            $this->assertNotNull($pair['transfer_id']);
            $this->assertSame(0, (int) $pair['net'], 'Abgang und Zugang heben sich auf');
        }

        $this->assertSame('transfer_reversal_out,transfer_reversal_in', $pairs[2]['types']);
        $this->assertSame(1, (int) $this->reports->getTodayTransfers()[0]['quantity']);
    }

    public function testMigrationLinksExistingTransfers(): void
    {
        $this->receive(4, null);

        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);
        $this->stock->transferAllStock($this->mainId, $this->boxId);

        // Stand vor Migration 009 simulieren: Paare nur über "ID + 1".
        $this->db->exec('UPDATE stock_movements SET transfer_id = NULL');
        $this->assertSame([], $this->reports->getTodayTransfers());

        $sql = file_get_contents(__DIR__ . '/../database/migrations/009_transfer_id.sql');
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if (str_starts_with($statement, 'UPDATE')) {
                $this->db->exec($statement);
            }
        }

        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM stock_movements WHERE movement_type LIKE 'transfer%' AND transfer_id IS NULL"
        )->fetchColumn());

        $this->assertSame(4, (int) $this->reports->getTodayTransfers()[0]['quantity']);
    }
}
