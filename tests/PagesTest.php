<?php

namespace LagerApp\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Seiten-Tests über echtes HTTP: startet einen PHP-Entwicklungsserver mit
 * einer frischen Demo-Datenbank (script/demo-data.php), ruft jede Seite
 * auf und spielt die wichtigsten Abläufe durch.
 *
 * Fängt Fehler ab, die die Logik-Tests nicht sehen: PHP-Warnungen oder
 * -Fehler in den Vorlagen, fehlende Variablen, kaputte Formulare. Der
 * Server läuft mit display_errors=1 und error_reporting=E_ALL, damit jede
 * Warnung/Notice im HTML landet und den Test scheitern lässt.
 */
class PagesTest extends TestCase
{
    private const ERROR_MARKERS = [
        'Fatal error',
        'Parse error',
        'Warning:',
        'Notice:',
        'Deprecated:',
        'Uncaught',
        'technischer Fehler',
        'Stack trace',
    ];

    /** @var resource|null */
    private static $server = null;

    private static string $dir;
    private static string $baseUrl;
    private static PDO $db;
    private static string $cookie = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__);
        self::$dir = sys_get_temp_dir() . '/sanlager-pages-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);

        $dbFile = self::$dir . '/demo.sqlite';

        exec(sprintf('php %s %s 2>&1', escapeshellarg($root . '/script/demo-data.php'), escapeshellarg($dbFile)), $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('Demo-Datenbank konnte nicht angelegt werden: ' . implode("\n", $output));
        }

        self::$db = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Freien Port ermitteln.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$baseUrl = 'http://127.0.0.1:' . $port;

        self::$server = proc_open(
            [
                PHP_BINARY,
                '-d', 'variables_order=EGPCS',
                '-d', 'display_errors=1',
                '-d', 'error_reporting=-1',
                '-d', 'log_errors=0',
                // Meldungen als Klartext ("Warning: ..."), nicht als
                // "<b>Warning</b>:" – sonst erkennt ERROR_MARKERS sie nicht.
                '-d', 'html_errors=0',
                '-S', '127.0.0.1:' . $port,
                '-t', $root . '/public',
            ],
            [1 => ['file', self::$dir . '/server.log', 'a'], 2 => ['file', self::$dir . '/server.log', 'a']],
            $pipes,
            $root,
            getenv() + ['DB_DATABASE' => $dbFile, 'APP_DEBUG' => 'false', 'APP_TIMEZONE' => 'Europe/Berlin']
        );

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($socket) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('Testserver startet nicht: ' . @file_get_contents(self::$dir . '/server.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        array_map('unlink', glob(self::$dir . '/*') ?: []);
        @rmdir(self::$dir);
    }

    /**
     * @return array{status: int, headers: array<int, string>, body: string, location: ?string}
     */
    private static function request(string $path, array $post = [], bool $sameOrigin = true): array
    {
        $headers = [];

        if (self::$cookie !== '') {
            $headers[] = 'Cookie: ' . self::$cookie;
        }

        if ($post !== [] && $sameOrigin) {
            $headers[] = 'Origin: ' . self::$baseUrl;
        }

        $options = [
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => $headers,
        ];

        if ($post !== []) {
            $options['method'] = 'POST';
            $options['content'] = http_build_query($post);
            $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $body = file_get_contents(self::$baseUrl . '/' . ltrim($path, '/'), false, stream_context_create(['http' => $options]));
        $responseHeaders = http_get_last_response_headers() ?? [];

        preg_match('#^HTTP/\S+ (\d+)#', $responseHeaders[0] ?? '', $status);

        $location = null;

        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie: (sanlager=[^;]+)/i', $header, $match)) {
                self::$cookie = $match[1];
            }

            if (preg_match('/^Location: (.+)$/i', $header, $match)) {
                $location = trim($match[1]);
            }
        }

        return [
            'status' => (int) ($status[1] ?? 0),
            'headers' => $responseHeaders,
            'body' => (string) $body,
            'location' => $location,
        ];
    }

    private function assertCleanPage(array $response, string $context): void
    {
        $this->assertSame(200, $response['status'], $context . ': HTTP-Status');

        foreach (self::ERROR_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $response['body'], $context . ': "' . $marker . '" in der Seite');
        }
    }

    private static function id(string $sql): int
    {
        return (int) self::$db->query($sql)->fetchColumn();
    }

    /**
     * Platzhalter in Test-URLs durch IDs aus der Demo-Datenbank ersetzen.
     */
    private static function resolve(string $path): string
    {
        return strtr($path, [
            '{article}' => (string) self::id("SELECT id FROM articles WHERE article_number = 'diag-bz-streifen'"),
            '{location}' => (string) self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 1'"),
            '{category}' => (string) self::id('SELECT MIN(id) FROM article_categories'),
        ]);
    }

    public static function pages(): array
    {
        return [
            'Buchen' => ['?page=issue', 'Buchen'],
            'Startseite' => ['', 'Buchen'],
            'Unbekannte Seite' => ['?page=gibt-es-nicht', 'Buchen'],
            'Heute ausgebucht' => ['?page=today_issues', 'Heute umgebucht'],
            'MHD-Übersicht' => ['?page=expiry', 'ABGELAUFEN'],
            'Auffüllen' => ['?page=restock', 'Heftpflaster 2,5 cm'],
            'Artikelliste' => ['?page=articles', 'Mullbinde 8 cm'],
            'Artikelliste Suche' => ['?page=articles&search=binde', 'Mullbinde 8 cm'],
            'Artikelliste Kategorie' => ['?page=articles&category={category}', 'Artikel'],
            'Artikel' => ['?page=article&id={article}', 'Bestand nach MHD'],
            'Artikel bearbeiten' => ['?page=edit_article&id={article}', 'Artikel löschen'],
            'Neuer Artikel' => ['?page=new_article', 'Artikelnummer'],
            'Etikett' => ['?page=label&id={article}', 'diag-bz-streifen'],
            'Kategorien' => ['?page=categories', 'Verbandmaterial'],
            'Kategorie bearbeiten' => ['?page=categories&edit={category}', 'Kategorie speichern'],
            'Lagerorte' => ['?page=locations', 'Rucksack 3'],
            'Lagerort bearbeiten' => ['?page=locations&edit={location}', 'Lagerort speichern'],
            'Lagerort-Inhalt' => ['?page=location&id={location}', 'Blutzuckermessstreifen'],
        ];
    }

    #[DataProvider('pages')]
    public function testPageRendersWithoutErrors(string $path, string $expectedText): void
    {
        $response = self::request(self::resolve($path));

        $this->assertCleanPage($response, $path);
        $this->assertStringContainsString($expectedText, $response['body'], $path);
        $this->assertStringContainsString('</html>', $response['body'], $path . ': Seite vollständig');
    }

    public function testUnknownIdsRedirectInsteadOfFailing(): void
    {
        foreach (['?page=article&id=999999' => 'page=articles', '?page=location&id=999999' => 'page=locations'] as $path => $target) {
            $response = self::request($path);

            $this->assertSame(302, $response['status'], $path);
            $this->assertStringContainsString($target, (string) $response['location'], $path);
        }
    }

    public function testNavigationShowsSectionsTabsAndCounts(): void
    {
        $expiry = self::request('?page=expiry')['body'];

        $this->assertMatchesRegularExpression('#href="\?page=expiry"\s+class="active"\s*>\s*Kontrolle <span class="nav-badge">\d+</span>#', $expiry);
        $this->assertStringContainsString('class="sub-nav"', $expiry);
        $this->assertStringContainsString('href="?page=restock"', $expiry);

        $articles = self::request('?page=articles')['body'];

        $this->assertMatchesRegularExpression('#class="active"\s*>\s*Verwaltung#', $articles);
        $this->assertStringContainsString('href="?page=categories"', $articles);

        // Detailseiten: Bereich markiert, aber keine Reiter (Zurück-Link genügt).
        $location = self::request(self::resolve('?page=location&id={location}'))['body'];

        $this->assertMatchesRegularExpression('#class="active"\s*>\s*Verwaltung#', $location);
        $this->assertStringNotContainsString('class="sub-nav"', $location);

        // Buchen: keine Reiter.
        $this->assertStringNotContainsString('class="sub-nav"', self::request('?page=issue')['body']);
    }

    public function testRestockListGroupsByLocation(): void
    {
        $main = self::id("SELECT id FROM storage_locations WHERE name = 'Hauptlager'");
        $bag = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 1'");

        $response = self::request('?page=restock');

        $this->assertCleanPage($response, 'Auffüllen');

        // Hauptlager ist der Standard-Lagerort: Fehlendes muss nachbestellt werden.
        $this->assertStringContainsString('nachbestellen', $response['body']);

        // Rucksack 1: Umbuchen aus dem Hauptlager direkt vorbelegt.
        $this->assertStringContainsString('?page=issue&source=' . $main . '&target=' . $bag, $response['body']);
        $this->assertStringContainsString('Aus Hauptlager umbuchen', $response['body']);
    }

    public function testBookingShowsMessageOnceAndKeepsDirection(): void
    {
        $source = self::id("SELECT id FROM storage_locations WHERE name = 'Hauptlager'");
        $target = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 3'");

        $response = self::request('?page=issue', [
            'action' => 'issue',
            'article_number' => 'verb-mullbinde-8',
            'source' => $source,
            'target' => $target,
        ]);

        $this->assertSame(302, $response['status']);

        $page = self::request((string) $response['location']);

        $this->assertCleanPage($page, 'nach Umbuchung');
        $this->assertStringContainsString('Mullbinde 8 cm – 1 Stück umgebucht nach Rucksack 3', $page['body']);
        $this->assertStringContainsString('Umbuchen: Hauptlager → Rucksack 3', $page['body']);

        $again = self::request('?page=issue');

        $this->assertStringNotContainsString('umgebucht nach Rucksack 3', $again['body'], 'Meldung nur einmal');
    }

    public function testForeignPostIsRejected(): void
    {
        $before = self::id("SELECT COUNT(*) FROM stock_movements");

        $response = self::request('?page=issue', ['action' => 'issue', 'article_number' => 'verb-mullbinde-8'], sameOrigin: false);

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Anfrage abgelehnt', $response['body']);
        $this->assertSame($before, self::id("SELECT COUNT(*) FROM stock_movements"));
    }

    public function testScannerReturnsJson(): void
    {
        $response = self::request('', ['action' => 'issue', 'ajax' => '1', 'article_number' => 'verb-kompresse-10']);

        $this->assertSame(200, $response['status']);
        $this->assertTrue(json_decode($response['body'], true)['success'] ?? false, $response['body']);

        $error = self::request('', ['action' => 'issue', 'ajax' => '1', 'article_number' => 'GIBT-ES-NICHT']);

        $this->assertSame(400, $error['status']);
        $this->assertStringContainsString('nicht gefunden', json_decode($error['body'], true)['error'] ?? '');
    }

    public function testDisposeUndoAndArticleBooking(): void
    {
        $articleId = self::id("SELECT id FROM articles WHERE article_number = 'inf-vvk-18g'");
        $batchId = self::id("SELECT id FROM batches WHERE article_id = $articleId AND expiry_date < date('now')");
        $mainId = self::id("SELECT id FROM storage_locations WHERE name = 'Hauptlager'");

        $dispose = self::request('?page=expiry', [
            'action' => 'dispose_batch',
            'article_id' => $articleId,
            'batch_id' => $batchId,
            'location_id' => $mainId,
        ]);

        $this->assertSame(302, $dispose['status']);
        $this->assertCleanPage(self::request('?page=expiry'), 'MHD nach Entsorgen');

        $today = self::request('?page=today_issues');
        $this->assertCleanPage($today, 'Heute nach Entsorgen');
        $this->assertStringContainsString('entsorgt', $today['body']);

        $undo = self::request('?page=today_issues', [
            'action' => 'undo_disposal',
            'article_id' => $articleId,
            'batch_id' => $batchId,
            'location_id' => $mainId,
            'quantity' => 4,
        ]);

        $this->assertSame(302, $undo['status']);
        $this->assertStringContainsString('Entsorgung rückgängig', self::request('?page=today_issues')['body']);

        $move = self::request('?page=article&id=' . $articleId, [
            'action' => 'stock_move',
            'article_id' => $articleId,
            'from' => 'receipt',
            'to' => $mainId,
            'batch_selection' => 'new',
            'expiry_date' => '31.12.2030',
            'quantity' => 2,
        ]);

        $this->assertSame(302, $move['status']);

        $article = self::request((string) $move['location']);
        $this->assertCleanPage($article, 'Artikel nach Einlagern');
        $this->assertStringContainsString('2 Stück eingelagert in Hauptlager', $article['body']);
        $this->assertStringContainsString('MHD: 31.12.2030', $article['body']);
    }

    public function testInvalidInputShowsMessageNotError(): void
    {
        $response = self::request('?page=categories', [
            'action' => 'create_category',
            'name' => 'Test',
            'short_name' => 'T',
            'color' => 'rot',
        ]);

        $this->assertCleanPage($response, 'ungültige Kategorie');
        $this->assertStringContainsString('Ungültige Farbe.', $response['body']);
    }
}
