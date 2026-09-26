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

        $this->mainId = (int) $locations->defaultLocation()['id'];
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
        $this->assertSame(4, $this->stock->getStockSummary($this->articleId)['total']);
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

    public function testIssueOldestWithQuantitySpansBatchesOldestFirst(): void
    {
        $this->receive(5, null);
        $late = $this->receive(5, $this->day('+2 years'));
        $early = $this->receive(2, $this->day('+1 year'));

        $result = $this->stock->issueOldest($this->articleId, $this->mainId, null, 4);

        $this->assertSame($early, $result['batch_id']);
        $this->assertSame(
            [[$early, 2], [$late, 2]],
            array_map(fn (array $batch): array => [$batch['batch_id'], $batch['quantity']], $result['batches'])
        );
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $early));
        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $late));
        $this->assertSame(8, $this->stock->getStockSummary($this->articleId)['total']);
    }

    public function testIssueOldestWithTooLargeQuantityBooksNothing(): void
    {
        $this->receive(2, $this->day('+1 year'));
        $this->receive(1, null);

        try {
            $this->stock->issueOldest($this->articleId, $this->mainId, null, 4);
            $this->fail('Mehr ausgebucht als vorhanden.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nur 3 vorhanden', $exception->getMessage());
        }

        $this->assertSame(3, $this->stock->getStockSummary($this->articleId)['total']);
    }

    public function testTransferOldestWithQuantityKeepsEachBatch(): void
    {
        $early = $this->receive(1, $this->day('+1 year'));
        $late = $this->receive(4, $this->day('+2 years'));

        $result = $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId, null, 3);

        $this->assertCount(2, $result['batches']);
        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $early));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $late));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $late));
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
        $this->assertSame(3, $this->stock->getStockSummary($this->articleId)['total']);
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

        $this->assertSame(6, array_sum(array_column($moved, 'quantity')));
        $this->assertSame("6\u{00A0}Stück", quantitiesByUnit($moved));
        $this->assertFalse($this->stock->locationHasStock($this->boxId));
        $this->assertSame(4, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId));
    }

    public function testTransferAllStockFromEmptyLocationMovesNothing(): void
    {
        $this->assertSame([], $this->stock->transferAllStock($this->boxId, $this->mainId));
    }

    public function testExpiredStockIsNotCountedAsUsable(): void
    {
        $this->receive(3, $this->day('-1 day'));
        $this->receive(2, $this->day('+1 year'));
        $this->receive(1, null);
        $this->receive(4, $this->day('today'));

        // Ein MHD von heute gilt noch als verwendbar.
        $this->assertSame(7, $this->stock->getStockSummary($this->articleId)['total']);
        $this->assertSame(3, $this->stock->getStockSummary($this->articleId)['expired']);

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

        $this->assertFalse($this->stock->getStockSummary($this->articleId)['is_low']);

        $this->stock->saveMinimums($this->articleId, [$this->mainId => 3]);
        $this->assertFalse($this->stock->getStockSummary($this->articleId)['is_low']);

        $this->stock->saveMinimums($this->articleId, [$this->mainId => 4]);
        $this->assertTrue($this->stock->getStockSummary($this->articleId)['is_low']);

        $this->stock->saveMinimums($this->articleId, [$this->mainId => null]);
        $this->assertFalse($this->stock->getStockSummary($this->articleId)['is_low']);
    }

    public function testTodayIssuesCountOnlyIssuesNotTransfers(): void
    {
        $this->receive(5, null);

        $this->stock->issueOldest($this->articleId, $this->mainId);
        $this->stock->issueOldest($this->articleId, $this->mainId);
        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);

        $this->assertSame(2, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));

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

        $this->assertSame(0, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));
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
        $this->assertSame(2, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));
        $this->assertSame(2, (int) $this->reports->getTodayIssues()[0]['quantity']);

        // Die Historie bleibt erhalten: nichts gelöscht, eine Gegenbuchung mehr.
        $types = $this->db->query('SELECT movement_type FROM stock_movements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['receipt', 'issue', 'issue', 'issue', 'issue_reversal'], $types);

        $this->stock->reverseTodayIssue($this->articleId, $batch, $this->mainId, 2);

        $this->assertSame([], $this->reports->getTodayIssues());
        $this->assertSame(0, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));
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
        $this->assertSame(2, $this->stock->getStockSummary($this->articleId)['total']);

        // Entsorgen ist kein Verbrauch.
        $this->assertSame(0, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));
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
        $this->assertSame(0, array_sum(array_column($this->reports->getTodayIssues(), 'quantity')));
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

        $this->assertTrue($this->stock->getStockSummary($this->articleId)['is_low']);

        // Kiste wird aufgelöst: leer, also deaktivierbar – ihr Mindestbestand
        // bleibt gespeichert, darf aber nicht mehr zählen.
        (new LocationRepository($this->db))->deactivate($this->boxId);

        $this->assertFalse($this->stock->getStockSummary($this->articleId)['is_low']);
        $this->assertFalse($this->stock->getStockSummaries()[$this->articleId]['is_low']);
        $this->assertSame([], $this->reports->getLowStockItems());
    }

    public function testUsableQuantitiesAtLocationSkipExpiredAndEmpty(): void
    {
        $this->receive(4, $this->day('-1 day'));
        $this->receive(3, $this->day('+1 year'));
        $this->receive(2, null);
        $this->receive(5, null, $this->boxId);

        $this->assertSame([$this->articleId => 5], $this->reports->getUsableQuantitiesAtLocation($this->mainId));
        $this->assertSame([$this->articleId => 5], $this->reports->getUsableQuantitiesAtLocation($this->boxId));

        // Nur noch Abgelaufenes übrig: Artikel taucht nicht mehr auf.
        $this->stock->move($this->articleId, $this->mainId, 3, 'issue', null, $this->batches->findOrCreate($this->articleId, $this->day('+1 year')));
        $this->stock->move($this->articleId, $this->mainId, 2, 'issue', null, null);

        $this->assertSame([], $this->reports->getUsableQuantitiesAtLocation($this->mainId));
    }

    public function testCountExpiredBatchesCountsOnlyExpiredStockPerLocation(): void
    {
        $this->receive(4, $this->day('-1 day'));
        $this->receive(2, $this->day('-1 day'), $this->boxId);
        $this->receive(3, $this->day('+1 year'));

        $this->assertSame(2, $this->reports->countExpiredBatches());

        // Aus der Kiste entsorgt: dort kein Bestand mehr, zählt nicht.
        $this->stock->move($this->articleId, $this->boxId, 2, 'disposal', null, $this->batches->findOrCreate($this->articleId, $this->day('-1 day')));

        $this->assertSame(1, $this->reports->countExpiredBatches());
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

    public function testLocationChecklistListsStockAndMonitoredArticles(): void
    {
        $articles = new ArticleRepository($this->db);

        // Mullbinde: zwei Chargen in der Kiste (eine abgelaufen), Mindestbestand 5.
        $old = $this->receive(1, $this->day('-1 month'), $this->boxId);
        $new = $this->receive(2, $this->day('+1 year'), $this->boxId);
        $this->stock->saveMinimums($this->articleId, [$this->boxId => 5]);

        // Überwacht, aber nichts da; ohne MHD – zum Zählen eine Zeile mit 0.
        $gauze = $articles->create('A-002', 'Kompresse', '', 'Stück', null, false);
        $this->stock->saveMinimums($gauze, [$this->boxId => 2]);

        // Überwacht, mit MHD, nichts da: keine Charge, nur der Artikel.
        $plaster = $articles->create('A-003', 'Pflaster', '', 'Stück', null);
        $this->stock->saveMinimums($plaster, [$this->boxId => 1]);

        // Nur im Hauptlager: gehört nicht auf die Liste der Kiste.
        $scissors = $articles->create('A-004', 'Schere', '', 'Stück', null, false);
        $this->stock->move($scissors, $this->mainId, 1, 'receipt');

        $list = $this->stock->getLocationChecklist($this->boxId);

        $this->assertSame(['Kompresse', 'Mullbinde', 'Pflaster'], array_column($list, 'article_name'));

        [$gauzeRow, $bandageRow, $plasterRow] = $list;

        $this->assertSame(5, $bandageRow['minimum_stock']);
        $this->assertSame(3, $bandageRow['quantity']);
        $this->assertSame(2, $bandageRow['usable_quantity']);
        $this->assertSame(3, $bandageRow['missing_quantity']);
        $this->assertSame([$old, $new], array_column($bandageRow['batches'], 'batch_id'));
        $this->assertSame([1, 2], array_column($bandageRow['batches'], 'quantity'));

        $this->assertSame([['batch_id' => null, 'expiry_date' => null, 'quantity' => 0]], $gauzeRow['batches']);
        $this->assertSame(2, $gauzeRow['missing_quantity']);

        $this->assertSame([], $plasterRow['batches']);
        $this->assertSame(1, $plasterRow['missing_quantity']);

        // Nicht überwacht, aber vorhanden: ohne Soll.
        $this->stock->saveMinimums($this->articleId, [$this->boxId => null]);
        $bandageRow = $this->stock->getLocationChecklist($this->boxId)[1];
        $this->assertNull($bandageRow['minimum_stock']);
        $this->assertSame(0, $bandageRow['missing_quantity']);
    }

    public function testApplyInventoryBooksOnlyDifferencesAsCorrection(): void
    {
        $batch = $this->receive(5, $this->day('+1 year'), $this->boxId);
        $this->receive(2, null, $this->boxId);

        $changes = $this->stock->applyInventory($this->boxId, [
            ['article_id' => $this->articleId, 'batch_id' => $batch, 'counted' => 3],
            ['article_id' => $this->articleId, 'batch_id' => null, 'counted' => 2],
        ]);

        $this->assertSame([[
            'article_id' => $this->articleId,
            'batch_id' => $batch,
            'expected' => 5,
            'counted' => 3,
            'difference' => -2,
        ]], $changes);

        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $batch));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->boxId));

        $movement = $this->db->query(
            "SELECT quantity, note FROM stock_movements WHERE movement_type = 'correction'"
        )->fetchAll();

        $this->assertSame([['quantity' => -2, 'note' => 'Inventur: 3 gezählt, 5 erwartet']], $movement);

        // Mehr gefunden: Zugang. Korrekturen sind kein Verbrauch.
        $this->stock->applyInventory($this->boxId, [
            ['article_id' => $this->articleId, 'batch_id' => null, 'counted' => 4],
        ]);

        $this->assertSame(4, $this->stock->getStockAtLocation($this->articleId, $this->boxId));
        $this->assertSame([], $this->reports->getTodayIssues());
        $this->assertSame([], $this->reports->getTodayDisposals());
        $this->assertSame([], $this->reports->getIssuesBetween(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')));
    }

    public function testApplyInventoryRejectsNegativeCountAndBooksNothing(): void
    {
        $batch = $this->receive(5, $this->day('+1 year'), $this->boxId);

        try {
            $this->stock->applyInventory($this->boxId, [
                ['article_id' => $this->articleId, 'batch_id' => $batch, 'counted' => 1],
                ['article_id' => $this->articleId, 'batch_id' => null, 'counted' => -1],
            ]);
            $this->fail('Negative Zählung wurde angenommen.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(5, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $batch));
    }

    public function testSortOutExpiredTurnsIssueIntoDisposalOfWholeBatch(): void
    {
        $expired = $this->receive(3, $this->day('-1 day'));
        $this->receive(2, $this->day('+1 year'));

        $this->stock->issueOldest($this->articleId, $this->mainId);

        $disposed = $this->stock->sortOutExpired($this->articleId, $expired, $this->mainId, null, 1);

        // Das ausgebuchte Stück und der Rest der Charge: entsorgt, nicht verbraucht.
        $this->assertSame(3, $disposed);
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));
        $this->assertSame(2, $this->stock->getStockSummary($this->articleId)['total']);
        $this->assertSame([], $this->reports->getTodayIssues());
        $this->assertSame(3, (int) $this->reports->getTodayDisposals()[0]['quantity']);
    }

    public function testSortOutExpiredAfterTransferTakesItBack(): void
    {
        $expired = $this->receive(2, $this->day('-1 day'));

        $this->stock->transferOldest($this->articleId, $this->mainId, $this->boxId);

        $this->assertSame(2, $this->stock->sortOutExpired($this->articleId, $expired, $this->mainId, $this->boxId, 1));
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->boxId, $expired));
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));
        $this->assertSame([], $this->reports->getTodayTransfers());
    }

    public function testSortOutRejectsBatchThatIsNotExpiredAndBooksNothing(): void
    {
        $batch = $this->receive(2, $this->day('+1 year'));

        $this->stock->issueOldest($this->articleId, $this->mainId);

        try {
            $this->stock->sortOutExpired($this->articleId, $batch, $this->mainId, null, 1);
            $this->fail('Nicht abgelaufene Charge wurde aussortiert.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $batch));
        $this->assertCount(1, $this->reports->getTodayIssues());
    }
}
