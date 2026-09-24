<?php

namespace LagerApp\Tests;

use LagerApp\ActionResult;
use LagerApp\ArticleActions;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryActions;
use LagerApp\CategoryRepository;
use LagerApp\Database;
use LagerApp\LocationActions;
use LagerApp\LocationRepository;
use LagerApp\StockActions;
use LagerApp\StockReports;
use LagerApp\StockRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests der POST-Aktionen: Eingabeprüfung und Ergebnis (Redirect/JSON),
 * ohne dass Header gesendet oder die Ausführung beendet wird.
 */
class ActionsTest extends TestCase
{
    private PDO $db;
    private ArticleRepository $articles;
    private StockRepository $stock;
    private StockReports $reports;
    private BatchRepository $batches;
    private LocationRepository $locations;
    private CategoryRepository $categories;
    private int $articleId;
    private int $mainId;

    protected function setUp(): void
    {
        $this->db = (new Database(':memory:'))->connection();

        $this->articles = new ArticleRepository($this->db);
        $this->stock = new StockRepository($this->db);
        $this->reports = new StockReports($this->db);
        $this->batches = new BatchRepository($this->db);
        $this->locations = new LocationRepository($this->db);
        $this->categories = new CategoryRepository($this->db);

        $this->mainId = (int) $this->locations->findByName('Hauptlager')['id'];
        $this->articleId = $this->articles->create('A-001', 'Mullbinde', '', 'Stück', null);
    }

    private function stockActions(): StockActions
    {
        return new StockActions($this->articles, $this->locations, $this->stock, $this->batches);
    }

    private function articleActions(): ArticleActions
    {
        return new ArticleActions($this->articles, $this->categories, $this->stock);
    }

    private function receive(int $quantity): void
    {
        $this->stock->move($this->articleId, $this->mainId, $quantity, 'receipt');
    }

    public function testUnknownActionReturnsNull(): void
    {
        $this->assertNull($this->stockActions()->dispatch('something_else', []));
        $this->assertNull($this->articleActions()->dispatch(null, []));
    }

    public function testIssueByScannerReturnsJson(): void
    {
        $this->receive(2);

        $result = $this->stockActions()->dispatch('issue', [
            'ajax' => '1',
            'article_number' => ' A-001 ',
        ]);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->json['success']);
        $this->assertSame('Mullbinde', $result->json['article_name']);
        $this->assertSame('ausgebucht', $result->json['action_label']);
        $this->assertSame(1, $this->stock->getTotalStock($this->articleId));
    }

    public function testIssueByScannerReportsErrorAsJson(): void
    {
        $result = $this->stockActions()->dispatch('issue', [
            'ajax' => '1',
            'article_number' => 'GIBT-ES-NICHT',
        ]);

        $this->assertSame(400, $result->status);
        $this->assertFalse($result->json['success']);
        $this->assertStringContainsString('GIBT-ES-NICHT', $result->json['error']);
    }

    public function testIssueByFormRedirectsAndThrowsOnError(): void
    {
        $this->receive(1);

        $result = $this->stockActions()->dispatch('issue', ['article_number' => 'A-001']);

        $this->assertNull($result->json);
        $this->assertStringStartsWith('?page=issue&source=', $result->redirectUrl);
        $this->assertStringStartsWith('Mullbinde – 1 Stück ausgebucht', $result->message);
        $this->assertSame('success', $result->messageType);

        $this->expectException(RuntimeException::class);

        $this->stockActions()->dispatch('issue', ['article_number' => 'A-001']);
    }

    public function testIssueByFormKeepsSourceAndTargetForNextBooking(): void
    {
        $this->receive(2);
        $boxId = $this->locations->create('Kiste 1', '');

        $transfer = $this->stockActions()->dispatch('issue', [
            'article_number' => 'A-001',
            'source' => (string) $this->mainId,
            'target' => (string) $boxId,
        ]);

        $this->assertStringContainsString('&source=' . $this->mainId . '&target=' . $boxId, $transfer->redirectUrl);

        $issue = $this->stockActions()->dispatch('issue', ['article_number' => 'A-001']);

        $this->assertStringContainsString('&source=' . $this->mainId . '&target=issue', $issue->redirectUrl);
    }

    public function testStockMoveNormalizesGermanExpiryDate(): void
    {
        $result = $this->stockActions()->dispatch('stock_move', [
            'article_id' => (string) $this->articleId,
            'from' => 'receipt',
            'to' => (string) $this->mainId,
            'quantity' => '3',
            'batch_selection' => 'new',
            'expiry_date' => '31.12.2027',
        ]);

        $this->assertStringStartsWith('?page=article&id=' . $this->articleId, $result->redirectUrl);

        $batches = $this->stock->getStockByBatch($this->articleId);

        $this->assertSame('2027-12-31', $batches[0]['expiry_date']);
        $this->assertSame(3, (int) $batches[0]['quantity']);
    }

    public function testStockMoveRejectsInvalidExpiryDate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültiges MHD');

        try {
            $this->stockActions()->dispatch('stock_move', [
                'article_id' => (string) $this->articleId,
                'from' => 'receipt',
                'to' => (string) $this->mainId,
                'quantity' => '1',
                'batch_selection' => 'new',
                'expiry_date' => '31.02.2027',
            ]);
        } finally {
            $this->assertSame([], $this->stock->getStockByBatch($this->articleId));
        }
    }

    public function testManipulatedArrayInputIsTreatedAsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artikelnamen');

        $this->articleActions()->dispatch('create_article', [
            'category_id' => '1',
            'name' => ['nicht', 'erlaubt'],
            'article_number' => 'A-002',
        ]);
    }

    public function testSetMinimumsSavesAndRemovesMonitoring(): void
    {
        $this->articleActions()->dispatch('set_article_minimums', [
            'article_id' => (string) $this->articleId,
            'minimum_stock' => [(string) $this->mainId => '5'],
        ]);

        $this->assertTrue($this->stock->hasLowStockAtAnyLocation($this->articleId));

        $this->articleActions()->dispatch('set_article_minimums', [
            'article_id' => (string) $this->articleId,
            'minimum_stock' => [(string) $this->mainId => ''],
        ]);

        $this->assertFalse($this->stock->hasLowStockAtAnyLocation($this->articleId));
    }

    public function testReorderRejectsNonArray(): void
    {
        $result = (new LocationActions($this->locations, $this->stock))
            ->dispatch('reorder_locations', ['ids' => '1,2']);

        $this->assertSame(400, $result->status);
        $this->assertFalse($result->json['success']);
    }

    public function testCategoryCreateValidatesColor(): void
    {
        $actions = new CategoryActions($this->categories);

        try {
            $actions->dispatch('create_category', ['name' => 'Test', 'short_name' => 'T', 'color' => 'rot']);
            $this->fail('Ungültige Farbe wurde akzeptiert.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ungültige Farbe.', $exception->getMessage());
        }

        $result = $actions->dispatch('create_category', ['name' => 'Test', 'short_name' => 't', 'color' => '#aabbcc']);

        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame('T', $this->categories->find($this->db->query('SELECT MAX(id) FROM article_categories')->fetchColumn())['short_name']);
    }

    public function testStockSummariesMatchSingleArticleValues(): void
    {
        $other = $this->articles->create('A-002', 'Rettungsdecke', '', 'Stück', null);

        $expired = $this->batches->findOrCreate($this->articleId, date('Y-m-d', strtotime('-1 day')));
        $this->stock->move($this->articleId, $this->mainId, 2, 'receipt', null, $expired);
        $this->receive(4);
        $this->stock->saveMinimums($other, [$this->mainId => 1]);

        $summaries = $this->stock->getStockSummaries();

        $this->assertSame(['total' => 4, 'expired' => 2, 'is_low' => false], $summaries[$this->articleId]);
        $this->assertSame(['total' => 0, 'expired' => 0, 'is_low' => true], $summaries[$other]);
        $this->assertSame($summaries[$other], $this->stock->getStockSummary($other));
    }

    public function testUndoIssueAction(): void
    {
        $this->receive(3);
        $this->stock->issueOldest($this->articleId, $this->mainId);
        $this->stock->issueOldest($this->articleId, $this->mainId);

        $result = $this->stockActions()->dispatch('undo_issue', [
            'article_id' => (string) $this->articleId,
            'batch_id' => '0',
            'location_id' => (string) $this->mainId,
            'quantity' => '2',
        ]);

        $this->assertSame('?page=today_issues', $result->redirectUrl);
        $this->assertSame('Mullbinde – 2 Stück zurück nach Hauptlager gebucht', $result->message);
        $this->assertSame(3, $this->stock->getTotalStock($this->articleId));
    }

    public function testDisposeActionRedirectsBackToOrigin(): void
    {
        $expired = $this->batches->findOrCreate($this->articleId, date('Y-m-d', strtotime('-3 days')));
        $this->stock->move($this->articleId, $this->mainId, 2, 'receipt', null, $expired);

        $result = $this->stockActions()->dispatch('dispose_batch', [
            'article_id' => (string) $this->articleId,
            'batch_id' => (string) $expired,
            'location_id' => (string) $this->mainId,
            'return' => 'location',
        ]);

        $this->assertSame('?page=location&id=' . $this->mainId, $result->redirectUrl);
        $this->assertStringContainsString('2 Stück aus Hauptlager entsorgt (rückgängig unter „Heute“)', $result->message);
        $this->assertSame(0, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));

        $this->expectException(RuntimeException::class);

        $this->stockActions()->dispatch('dispose_batch', [
            'article_id' => (string) $this->articleId,
            'batch_id' => (string) $expired,
            'location_id' => '9999',
        ]);
    }

    public function testUndoTransferAndDisposalActions(): void
    {
        $boxId = $this->locations->create('Kiste 1', '');
        $this->receive(2);
        $this->stock->transferOldest($this->articleId, $this->mainId, $boxId);

        $result = $this->stockActions()->dispatch('undo_transfer', [
            'article_id' => (string) $this->articleId,
            'batch_id' => '0',
            'location_id' => (string) $this->mainId,
            'to_location_id' => (string) $boxId,
            'quantity' => '1',
        ]);

        $this->assertStringContainsString('von Kiste 1 zurück nach Hauptlager', $result->message);
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId));

        $expired = $this->batches->findOrCreate($this->articleId, date('Y-m-d', strtotime('-2 days')));
        $this->stock->move($this->articleId, $this->mainId, 1, 'receipt', null, $expired);
        $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId);

        $this->stockActions()->dispatch('undo_disposal', [
            'article_id' => (string) $this->articleId,
            'batch_id' => (string) $expired,
            'location_id' => (string) $this->mainId,
            'quantity' => '1',
        ]);

        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $expired));
    }

    public function testStockMoveTransfersSelectedBatchAndKeepsFormState(): void
    {
        $boxId = $this->locations->create('Kiste 1', '');
        $old = $this->batches->findOrCreate($this->articleId, '2027-01-31');
        $new = $this->batches->findOrCreate($this->articleId, '2028-01-31');
        $this->stock->move($this->articleId, $this->mainId, 5, 'receipt', null, $old);
        $this->stock->move($this->articleId, $this->mainId, 5, 'receipt', null, $new);

        // Bewusst die neuere Charge umbuchen – anders als beim Scannen (FIFO).
        $result = $this->stockActions()->dispatch('stock_move', [
            'article_id' => (string) $this->articleId,
            'from' => (string) $this->mainId,
            'to' => (string) $boxId,
            'batch_selection' => (string) $new,
            'quantity' => '3',
        ]);

        $this->assertSame(5, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $old));
        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId, $new));
        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $boxId, $new));

        $this->assertStringContainsString(
            '&from=' . $this->mainId . '&to=' . $boxId,
            $result->redirectUrl
        );
        $this->assertSame('3 Stück umgebucht: Hauptlager → Kiste 1', $result->message);

        // Erscheint in "Heute umgebucht" und lässt sich zurücknehmen.
        $this->assertSame(3, (int) $this->reports->getTodayTransfers()[0]['quantity']);
    }

    public function testStockMoveDerivesMovementFromVonNach(): void
    {
        $this->stockActions()->dispatch('stock_move', [
            'article_id' => (string) $this->articleId,
            'from' => 'receipt',
            'to' => (string) $this->mainId,
            'quantity' => '4',
        ]);

        $result = $this->stockActions()->dispatch('stock_move', [
            'article_id' => (string) $this->articleId,
            'from' => (string) $this->mainId,
            'to' => 'issue',
            'quantity' => '1',
        ]);

        $this->assertSame(3, $this->stock->getStockAtLocation($this->articleId, $this->mainId));
        $this->assertSame(1, $this->reports->getTodayIssueCount());
        $this->assertSame('1 Stück ausgebucht aus Hauptlager', $result->message);
        $this->assertStringContainsString('&from=' . $this->mainId . '&to=issue', $result->redirectUrl);
    }

    public function testStockMoveVonNachValidation(): void
    {
        $this->receive(2);
        $boxId = $this->locations->create('Kiste 1', '');

        $base = [
            'article_id' => (string) $this->articleId,
            'from' => (string) $this->mainId,
            'batch_selection' => 'none',
            'quantity' => '1',
        ];

        foreach ([
            'ohne Nach' => [[], 'gültige Lagerorte'],
            'Einlagern → Ausbuchen' => [['from' => 'receipt', 'to' => 'issue'], 'Lagerort auswählen'],
            'Von = Nach' => [['to' => (string) $this->mainId], 'nicht derselbe'],
            'unbekannter Lagerort' => [['to' => '9999'], 'gültige Lagerorte'],
            'neues MHD beim Umbuchen' => [['to' => (string) $boxId, 'batch_selection' => 'new', 'expiry_date' => '2030-01-01'], 'nur beim Einlagern'],
            'zu viel' => [['to' => (string) $boxId, 'quantity' => '3'], 'Nicht genügend Bestand'],
        ] as $case => [$extra, $expected]) {
            try {
                $this->stockActions()->dispatch('stock_move', $extra + $base);
                $this->fail('Nicht abgelehnt: ' . $case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage(), $case);
            }
        }

        $this->assertSame(2, $this->stock->getStockAtLocation($this->articleId, $this->mainId));
    }

    public function testArticleWithStockCannotBeDeleted(): void
    {
        // Nur abgelaufener Bestand – zählt trotzdem, er liegt ja noch da.
        $expired = $this->batches->findOrCreate($this->articleId, date('Y-m-d', strtotime('-1 day')));
        $this->stock->move($this->articleId, $this->mainId, 2, 'receipt', null, $expired);

        try {
            $this->articleActions()->dispatch('deactivate_article', ['id' => (string) $this->articleId]);
            $this->fail('Artikel mit Bestand wurde gelöscht.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('noch Bestand vorhanden ist (2 Stück', $exception->getMessage());
        }

        $this->assertSame(1, (int) $this->articles->find($this->articleId)['active']);

        $this->stock->disposeExpiredBatch($this->articleId, $expired, $this->mainId);

        $result = $this->articleActions()->dispatch('deactivate_article', ['id' => (string) $this->articleId]);

        $this->assertSame('?page=articles', $result->redirectUrl);
        $this->assertSame('Artikel gelöscht', $result->message);
        $this->assertSame(0, (int) $this->articles->find($this->articleId)['active']);
    }

    public function testDeletedArticleFreesNumberAndName(): void
    {
        $this->articleActions()->dispatch('deactivate_article', ['id' => (string) $this->articleId]);

        // Gleiche Nummer und gleicher Name wie der gelöschte Artikel.
        $newId = $this->articles->create('A-001', 'Mullbinde', '', 'Stück', null);

        $this->assertNotSame($this->articleId, $newId);
        $this->assertSame($newId, (int) $this->articles->findByArticleNumber('A-001')['id']);

        // Der gelöschte bleibt (mit seinen Buchungen) erhalten, nur ohne Nummer.
        $this->assertNull($this->articles->find($this->articleId)['article_number']);
        $this->assertSame(0, (int) $this->articles->find($this->articleId)['active']);

        // Auch beim Bearbeiten lässt sich die Nummer eines gelöschten übernehmen.
        $otherId = $this->articles->create('B-001', 'Pflaster', '', 'Stück', null);
        $this->articleActions()->dispatch('deactivate_article', ['id' => (string) $otherId]);
        $this->articles->update($newId, 'B-001', 'Mullbinde', '', 'Stück', null);

        $this->assertSame($newId, (int) $this->articles->findByArticleNumber('B-001')['id']);
    }

    public function testActiveArticleKeepsNumberAndNameCaseInsensitive(): void
    {
        foreach ([['A-001', 'Andere Binde', 'Artikelnummer wird bereits verwendet (Mullbinde)'],
                  ['A-002', ' mullBINDE ', 'Namen existiert bereits (Mullbinde)']] as [$number, $name, $expected]) {
            try {
                $this->articles->create($number, $name, '', 'Stück', null);
                $this->fail('Doppelter Artikel angelegt: ' . $name);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }

        // Umlaute: SQLite (COLLATE NOCASE) würde "ärmel" und "Ärmel" unterscheiden.
        $this->articles->create('A-003', 'Ärmelschoner', '', 'Stück', null);

        $this->expectExceptionMessage('Namen existiert bereits');
        $this->articles->create('A-004', 'ärmelschoner', '', 'Stück', null);
    }

    public function testArticleNumberIsRequiredWhenEditing(): void
    {
        $category = (string) $this->categories->all()[0]['id'];

        try {
            $this->articleActions()->dispatch('update_article', [
                'id' => (string) $this->articleId,
                'article_number' => '  ',
                'name' => 'Mullbinde',
                'category_id' => $category,
            ]);
            $this->fail('Artikelnummer ließ sich leeren.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Bitte eine Artikelnummer eingeben.', $exception->getMessage());
        }

        $this->assertSame('A-001', $this->articles->find($this->articleId)['article_number']);
    }

    public function testCreatingDeactivatedLocationReactivatesIt(): void
    {
        $boxId = $this->locations->create('Kiste 1', 'alt');
        $this->stock->saveMinimums($this->articleId, [$boxId => 3]);
        $this->locations->deactivate($boxId);

        $result = (new LocationActions($this->locations, $this->stock))
            ->dispatch('create_location', ['name' => 'kiste 1', 'description' => 'neu']);

        $this->assertSame('Lagerort „kiste 1“ war deaktiviert und ist wieder aktiv', $result->message);

        $box = $this->locations->find($boxId);
        $this->assertSame(1, (int) $box['active']);
        $this->assertSame('kiste 1', $box['name']);
        $this->assertSame('neu', $box['description']);

        // Ans Ende der Sortierung, gespeicherter Mindestbestand gilt wieder.
        $this->assertSame($boxId, (int) array_column($this->locations->all(), 'id')[1]);
        $this->assertSame([$boxId], array_map('intval', array_column($this->reports->getLowStockItems(), 'location_id')));
    }

    public function testLocationNamesAreUniqueCaseInsensitive(): void
    {
        $boxId = $this->locations->create('Kiste 1', '');
        $oldId = $this->locations->create('Kiste 2', '');
        $this->locations->deactivate($oldId);

        try {
            $this->locations->create('KISTE 1', '');
            $this->fail('Doppelter Lagerort angelegt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existiert bereits (Kiste 1)', $exception->getMessage());
        }

        // Umbenennen auf den Namen eines deaktivierten: Hinweis, wie es geht.
        $this->expectExceptionMessage('So heißt ein deaktivierter Lagerort');
        $this->locations->update($boxId, 'Kiste 2', '');
    }

    public function testCategoryNamesAreUniqueCaseInsensitive(): void
    {
        $this->expectExceptionMessage('Eine Kategorie mit diesem Namen existiert bereits.');
        $this->categories->create('verbandMATERIAL', 'VM', '#ff0000');
    }

    public function testDefaultSourceIsFirstLocationInSortOrderNotName(): void
    {
        $boxId = $this->locations->create('Kiste 1', '');
        $this->stock->move($this->articleId, $boxId, 2, 'receipt');

        // Umbenennen ändert nichts, die Reihenfolge entscheidet.
        $this->locations->update($this->mainId, 'Zentrallager', '');
        $this->locations->reorder([$boxId, $this->mainId]);

        $this->assertSame($boxId, (int) $this->locations->defaultLocation()['id']);

        $result = $this->stockActions()->dispatch('issue', ['article_number' => 'A-001']);

        $this->assertStringContainsString('&source=' . $boxId, $result->redirectUrl);
        $this->assertSame(1, $this->stock->getStockAtLocation($this->articleId, $boxId));
    }

    public function testBookingExpiredBatchIsReportedAsError(): void
    {
        $expired = $this->batches->findOrCreate($this->articleId, date('Y-m-d', strtotime('-1 day')));
        $this->stock->move($this->articleId, $this->mainId, 1, 'receipt', null, $expired);

        $result = $this->stockActions()->dispatch('issue', ['article_number' => 'A-001']);

        $this->assertStringContainsString('ABGELAUFEN', $result->message);
        $this->assertSame('error', $result->messageType);
        $this->assertStringNotContainsString('ABGELAUFEN', $result->redirectUrl);
    }

    public function testNoActionPutsMessagesIntoTheUrl(): void
    {
        $sources = implode('', array_map('file_get_contents', glob(__DIR__ . '/../src/*Actions.php')));

        $this->assertDoesNotMatchRegularExpression('/[?&](message|success|expired)=/', $sources);
    }
}
