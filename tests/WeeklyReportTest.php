<?php

namespace LagerApp\Tests;

use DateTimeImmutable;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\Database;
use LagerApp\LocationRepository;
use LagerApp\ReportConfig;
use LagerApp\StockRepository;
use LagerApp\WeeklyReport;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WeeklyReportTest extends TestCase
{
    private PDO $db;
    private StockRepository $stock;
    private BatchRepository $batches;
    private int $mainId;
    private int $boxId;
    private int $bandageId;
    private int $blanketId;

    protected function setUp(): void
    {
        $this->db = (new Database(':memory:'))->connection();

        $this->stock = new StockRepository($this->db);
        $this->batches = new BatchRepository($this->db);

        $locations = new LocationRepository($this->db);
        $articles = new ArticleRepository($this->db);

        $this->mainId = (int) $locations->findByName('Hauptlager')['id'];
        $this->boxId = $locations->create('Kiste 1', '');

        $this->bandageId = $articles->create('A-001', 'Mullbinde', '', 'Stück', null);
        $this->blanketId = $articles->create('A-002', 'Rettungsdecke <gold>', '', 'Stück', null);
    }

    private function day(string $modifier): string
    {
        return date('Y-m-d', strtotime($modifier));
    }

    private function receive(int $articleId, int $quantity, ?string $expiry, int $locationId): void
    {
        $batchId = $expiry !== null ? $this->batches->findOrCreate($articleId, $expiry) : null;

        $this->stock->move($articleId, $locationId, $quantity, 'receipt', null, $batchId);
    }

    private function config(array $overrides = []): ReportConfig
    {
        return ReportConfig::fromEnv($overrides + [
            'MAILER_DSN' => 'null://null',
            'REPORT_FROM' => 'SanLager <lager@example.org>',
            'REPORT_RECIPIENTS' => 'leitung@example.org, material@example.org',
        ]);
    }

    public function testBuildSplitsExpiryAndFindsLowStock(): void
    {
        $this->receive($this->bandageId, 3, $this->day('-5 days'), $this->mainId);
        $this->receive($this->bandageId, 2, $this->day('+30 days'), $this->boxId);
        $this->receive($this->bandageId, 10, $this->day('+2 years'), $this->mainId);
        $this->receive($this->blanketId, 1, null, $this->boxId);

        // Hauptlager: 10 verwendbar (die 3 abgelaufenen zählen nicht) – ok.
        // Kiste 1: 1 Rettungsdecke, Minimum 4 – fehlen 3.
        $this->stock->saveMinimums($this->bandageId, [$this->mainId => 10]);
        $this->stock->saveMinimums($this->blanketId, [$this->boxId => 4]);

        $data = (new WeeklyReport($this->stock, 90))->build();

        $this->assertSame([$this->day('-5 days')], array_column($data['expired'], 'expiry_date'));
        $this->assertSame([$this->day('+30 days')], array_column($data['expiring'], 'expiry_date'));

        $this->assertCount(1, $data['low_stock']);
        $this->assertSame('Kiste 1', $data['low_stock'][0]['location_name']);
        $this->assertSame(3, $data['low_stock'][0]['missing_quantity']);
    }

    public function testExpiredStockDoesNotCountTowardsMinimum(): void
    {
        $this->receive($this->bandageId, 20, $this->day('-1 day'), $this->mainId);
        $this->stock->saveMinimums($this->bandageId, [$this->mainId => 5]);

        $data = (new WeeklyReport($this->stock))->build();

        $this->assertSame(5, $data['low_stock'][0]['missing_quantity']);
    }

    public function testLowStockIgnoresInactiveLocations(): void
    {
        $this->stock->saveMinimums($this->bandageId, [$this->boxId => 5]);
        (new LocationRepository($this->db))->deactivate($this->boxId);

        $this->assertSame([], $this->stock->getLowStockItems());
    }

    public function testIssuesCoverOnlyTheLastSevenDays(): void
    {
        $this->receive($this->bandageId, 10, null, $this->mainId);

        for ($i = 0; $i < 4; $i++) {
            $this->stock->issueOldest($this->bandageId, $this->mainId);
        }

        $this->stock->transferOldest($this->bandageId, $this->mainId, $this->boxId);

        // Zwei Ausbuchungen in die Vorwoche bzw. davor verschieben.
        $ids = $this->db->query("SELECT id FROM stock_movements WHERE movement_type = 'issue' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $utc = new \DateTimeZone('UTC');
        $update = $this->db->prepare('UPDATE stock_movements SET created_at = :created_at WHERE id = :id');
        $update->execute(['id' => $ids[0], 'created_at' => (new DateTimeImmutable('-3 days'))->setTimezone($utc)->format('Y-m-d H:i:s')]);
        $update->execute(['id' => $ids[1], 'created_at' => (new DateTimeImmutable('-10 days'))->setTimezone($utc)->format('Y-m-d H:i:s')]);

        // Stichtag morgen 0 Uhr: Zeitraum umfasst die letzten 7 Tage inkl. heute.
        $data = (new WeeklyReport($this->stock))->build(new DateTimeImmutable('tomorrow'));

        $this->assertSame(3, $data['issue_total']);
        $this->assertCount(1, $data['issues']);
        $this->assertSame('Hauptlager', $data['issues'][0]['location_name']);
    }

    public function testSubjectSummarizesFindings(): void
    {
        $report = new WeeklyReport($this->stock);

        $this->assertStringContainsString('keine Auffälligkeiten', $report->subject($report->build()));

        $this->receive($this->bandageId, 1, $this->day('-1 day'), $this->mainId);
        $this->stock->saveMinimums($this->blanketId, [$this->mainId => 1]);

        $subject = $report->subject($report->build());

        $this->assertStringContainsString('1 abgelaufen', $subject);
        $this->assertStringContainsString('1 unter Mindestbestand', $subject);
    }

    public function testEmailContainsEscapedHtmlTextAndLinks(): void
    {
        $this->stock->saveMinimums($this->blanketId, [$this->mainId => 2]);

        $report = new WeeklyReport($this->stock);
        $email = $report->createEmail(
            $this->config(['APP_URL' => 'https://lager.example.org/']),
            $report->build()
        );

        $this->assertCount(2, $email->getTo());
        $this->assertSame('lager@example.org', $email->getFrom()[0]->getAddress());

        $html = $email->getHtmlBody();
        $this->assertStringContainsString('Rettungsdecke &lt;gold&gt;', $html);
        $this->assertStringNotContainsString('<gold>', $html);
        $this->assertStringContainsString('https://lager.example.org/?page=article&amp;id=' . $this->blanketId, $html);

        $this->assertStringContainsString('Hauptlager: Rettungsdecke <gold> – 0 von 2 Stück, fehlen 2', $email->getTextBody());
    }

    public function testConfigReportsAllProblemsAtOnce(): void
    {
        try {
            ReportConfig::fromEnv([
                'REPORT_FROM' => 'kein-absender',
                'REPORT_RECIPIENTS' => 'ok@example.org, kaputt',
                'REPORT_EXPIRY_DAYS' => '0',
                'APP_URL' => 'lager.example.org',
            ]);
            $this->fail('Ungültige Konfiguration wurde akzeptiert.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();

            foreach (['MAILER_DSN', 'REPORT_FROM', 'kaputt', 'REPORT_EXPIRY_DAYS', 'APP_URL'] as $expected) {
                $this->assertStringContainsString($expected, $message);
            }
        }
    }

    public function testConfigDefaults(): void
    {
        $config = $this->config();

        $this->assertSame(90, $config->expiryDays);
        $this->assertNull($config->appUrl);
        $this->assertSame('SanLager', $config->from->getName());
    }
}
