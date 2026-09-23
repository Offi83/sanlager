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
        $this->assertStringStartsWith('?page=issue&success=', $result->redirectUrl);

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
            'location_id' => (string) $this->mainId,
            'quantity' => '3',
            'movement_type' => 'receipt',
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
                'location_id' => (string) $this->mainId,
                'quantity' => '1',
                'movement_type' => 'receipt',
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
}
