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
            'Artikel bearbeiten' => ['?page=edit_article&id={article}', 'name="unit_id"'],
            'Neuer Artikel' => ['?page=new_article', 'Artikelnummer'],
            'Etikett' => ['?page=label&id={article}', 'diag-bz-streifen'],
            'Etiketten Auswahl' => ['?page=labels', 'Alle Artikel'],
            'Etiketten Kategorie' => ['?page=labels&category={category}', 'Etiketten anzeigen'],
            'Kategorien' => ['?page=categories', 'Verbandmaterial'],
            'Kategorie bearbeiten' => ['?page=categories&edit={category}', 'Kategorie speichern'],
            'Einheiten' => ['?page=units', 'Flasche'],
            'Einheit bearbeiten' => ['?page=units&edit=1', 'Einheit speichern'],
            'Lagerorte' => ['?page=locations', 'Rucksack 3'],
            'Lagerort bearbeiten' => ['?page=locations&edit={location}', 'Lagerort speichern'],
            'Lagerort-Inhalt' => ['?page=location&id={location}', 'Blutzuckermessstreifen'],
            'Packliste' => ['?page=packlist&id={location}', 'Blutzuckermessstreifen'],
            'Inventur' => ['?page=inventory&id={location}', 'Blutzuckermessstreifen'],
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
        foreach ([
            '?page=article&id=999999' => 'page=articles',
            '?page=edit_article&id=999999' => 'page=articles',
            '?page=label&id=999999' => 'page=articles',
            '?page=location&id=999999' => 'page=locations',
            '?page=packlist&id=999999' => 'page=locations',
            '?page=inventory&id=999999' => 'page=locations',
        ] as $path => $target) {
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

        // Kategorie-Überschriften: Heftpflaster (Verbandmaterial) fehlt im Hauptlager.
        $this->assertMatchesRegularExpression('#article-category-row.*?<th colspan="4">\s*<span class="article-category-name">\s*Verbandmaterial#s', $response['body']);

        // Hauptlager: Einlagern direkt anbieten (Buchen mit Von = Einlagern).
        $this->assertMatchesRegularExpression('#href="\?page=issue&source=receipt&target=' . $main . '"[^>]*>\s*Einlagern\s*</a>#', $response['body']);
    }

    public function testCategoryRowsUseReadableTextColor(): void
    {
        // Demo-Kategorien wie in der echten Datenbank: Hygiene gelb, Diagnostik grün.
        $articles = self::request('?page=articles')['body'];

        $this->assertMatchesRegularExpression('#class="article-category-row"\s+style="background-color: \#fffb00; color: \#202124;"\s*>\s*<th colspan="4">\s*<span class="article-category-name">\s*Hygiene#', $articles);
        $this->assertMatchesRegularExpression('#style="background-color: \#0433ff; color: \#fff;"\s*>\s*<th colspan="4">\s*<span class="article-category-name">\s*Beatmung#', $articles);
        $this->assertStringContainsString('Instrumente', $articles);

        foreach (['?page=restock', '?page=expiry', '?page=labels', self::resolve('?page=location&id={location}'), self::resolve('?page=inventory&id={location}')] as $page) {
            $body = self::request($page)['body'];
            $this->assertDoesNotMatchRegularExpression('#class="article-category-row"\s+style="background-color: [^;"]+;"#', $body, $page);
        }
    }

    public function testExpiryOverviewGroupsByLocationLikeRestock(): void
    {
        $main = self::id("SELECT id FROM storage_locations WHERE name = 'Hauptlager'");
        $bag = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 1'");

        $body = self::request('?page=expiry')['body'];

        // Je Lagerort eine Karte, in der Reihenfolge der Lagerorte.
        $mainPos = strpos($body, 'id="location-' . $main . '"');
        $bagPos = strpos($body, 'id="location-' . $bag . '"');
        $this->assertNotFalse($mainPos);
        $this->assertNotFalse($bagPos);
        $this->assertLessThan($bagPos, $mainPos);
        $this->assertMatchesRegularExpression('#<h2>\s*<a href="\?page=location&id=' . $bag . '">\s*Rucksack 1\s*</a>\s*</h2>\s*<p>\s*1 abgelaufen#', $body);

        // Lagerort steht in der Überschrift, nicht mehr als Spalte; Kategorien wie beim Auffüllen.
        $this->assertStringNotContainsString('<th>Lagerort</th>', $body);
        $this->assertMatchesRegularExpression('#article-category-row.*?<span class="article-category-name">\s*Diagnostik#s', substr($body, $bagPos));
    }

    public function testDeletedArticleCannotBeOpenedByAddress(): void
    {
        self::$db->exec("INSERT INTO articles (article_number, name, unit_id, active) VALUES ('alt-geloescht', 'Gelöschter Artikel', 1, 0)");
        $deletedId = self::id("SELECT id FROM articles WHERE article_number = 'alt-geloescht'");

        foreach (['article', 'edit_article', 'label'] as $page) {
            $response = self::request('?page=' . $page . '&id=' . $deletedId);

            $this->assertSame(302, $response['status'], $page);
            $this->assertStringContainsString('page=articles', (string) $response['location'], $page);
        }
    }

    public function testArticlePageExplainsExpiredStockAndHidesEmptyBatches(): void
    {
        $articleId = self::id("SELECT id FROM articles WHERE article_number = 'diag-bz-streifen'");

        // Eine aufgebrauchte Charge: steht nicht mehr in der MHD-Auswahl.
        self::$db->exec("INSERT INTO batches (article_id, expiry_date) VALUES ($articleId, '2031-05-31')");

        $body = self::request('?page=article&id=' . $articleId)['body'];

        // Rucksack 1 hat nur abgelaufene Messstreifen.
        $this->assertMatchesRegularExpression('#davon 10 abgelaufen#', $body);
        $this->assertStringNotContainsString('31.05.2031', $body);

        // Abgelaufene Chargen sind für das Einlagern markiert.
        $this->assertMatchesRegularExpression('#data-expired="1"\s+data-expiry="\d{4}-\d{2}-\d{2}"#', $body);
    }

    public function testArticleWithoutExpiryHidesMhdFields(): void
    {
        $bandage = self::request('?page=article&id=' . self::id("SELECT id FROM articles WHERE article_number = 'verb-mullbinde-8'"))['body'];

        $this->assertCleanPage(['status' => 200, 'body' => $bandage], 'Artikel ohne MHD');
        $this->assertStringNotContainsString('Bestand nach MHD', $bandage);
        $this->assertStringNotContainsString('Neues MHD', $bandage);
        $this->assertMatchesRegularExpression('#id="batch-selection-field"\s+hidden#', $bandage);
        $this->assertStringContainsString('data-has-expiry="0"', $bandage);

        $strips = self::request(self::resolve('?page=article&id={article}'))['body'];

        $this->assertStringContainsString('Bestand nach MHD', $strips);
        $this->assertStringContainsString('Neues MHD', $strips);
        $this->assertMatchesRegularExpression('#id="batch-selection-field"\s+>#', $strips);

        // Ankreuzfeld beim Bearbeiten spiegelt die Einstellung.
        $edit = self::request('?page=edit_article&id=' . self::id("SELECT id FROM articles WHERE article_number = 'verb-mullbinde-8'"))['body'];
        $this->assertMatchesRegularExpression('#name="has_expiry"\s+value="1"\s+>#', $edit);
    }

    public function testTodayAndListsShowUnitsAndNoMhdDash(): void
    {
        // Oben nur die Anzahl je Art (Zeilen der Abschnitte darunter), jeder Abschnitt mit Überschrift.
        $today = self::request('?page=today_issues')['body'];
        $issues = substr_count($today, 'value="undo_issue"');
        $transfers = substr_count($today, 'value="undo_transfer"');
        $this->assertGreaterThan(1, $issues);
        $this->assertGreaterThan(1, $transfers);
        $this->assertMatchesRegularExpression('#href="\#heute-ausgebucht"[^>]*>\s*<strong>\s*' . $issues . '\s*</strong>\s*<span>\s*Ausbuchungen#', $today);
        $this->assertMatchesRegularExpression('#<strong>\s*0\s*</strong>\s*<span>\s*Entsorgungen#', $today);
        $this->assertMatchesRegularExpression('#href="\#heute-umgebucht"[^>]*>\s*<strong>\s*' . $transfers . '\s*</strong>\s*<span>\s*Umbuchungen#', $today);
        $this->assertMatchesRegularExpression('#<h2[^>]*id="heute-ausgebucht"[^>]*>\s*Heute ausgebucht#', $today);
        $this->assertStringNotContainsString('Heute entsorgt', $today);

        // Einlagerungen von heute (Lieferung), nicht die Erstausstattung der Demo.
        $receipts = substr_count($today, 'value="undo_receipt"');
        $this->assertGreaterThan(1, $receipts);
        $this->assertLessThan(5, $receipts);
        $this->assertMatchesRegularExpression('#href="\#heute-eingelagert"[^>]*>\s*<strong>\s*' . $receipts . '\s*</strong>\s*<span>\s*Einlagerungen#', $today);
        $this->assertMatchesRegularExpression('#<h2[^>]*id="heute-eingelagert"[^>]*>\s*Heute eingelagert#', $today);

        // Umbuchungen nach Richtung gruppiert, mit Artikelnummer wie die übrigen Listen.
        $transferSection = substr($today, strpos($today, 'id="heute-umgebucht"'));
        $this->assertMatchesRegularExpression('#class="table-group-row"[^>]*>\s*<th[^>]*>\s*Hauptlager → Rucksack 1\s*</th>#u', $transferSection);
        $this->assertStringContainsString('table-with-article-number', $transferSection);
        $this->assertStringContainsString('verb-kompresse-10', $transferSection);

        // Mullbinde hat kein MHD: "–" statt "ohne MHD".
        $this->assertMatchesRegularExpression('#Mullbinde 8 cm.*?<td>\s*–\s*</td>#s', $today);

        $bag = self::request(self::resolve('?page=location&id={location}'))['body'];
        $this->assertMatchesRegularExpression('#Mullbinde 8 cm.*?<span class="">\s*–\s*</span>#s', $bag);

        // Artikelliste: Einheit steht beim Bestand, keine eigene Spalte.
        $articles = self::request('?page=articles')['body'];
        $this->assertStringNotContainsString('<th>Einheit</th>', $articles);
        $this->assertMatchesRegularExpression('#<strong class="[^"]*">\s*\d+\s*</strong>\s*Rolle#', $articles);
    }

    public function testUnitsCanBeSortedAndPluralIsShown(): void
    {
        $ids = self::$db->query('SELECT id FROM units ORDER BY sort_order DESC')->fetchAll(PDO::FETCH_COLUMN);

        $response = self::request('?page=units', ['action' => 'reorder_units', 'ids' => $ids]);

        $this->assertSame(200, $response['status']);
        $this->assertTrue(json_decode($response['body'], true)['success'] ?? false, $response['body']);
        $this->assertSame(array_map('intval', $ids), array_map('intval', self::$db->query('SELECT id FROM units ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN)));

        // Heftpflaster fehlt im Hauptlager: "7 Rollen" statt "7 Rolle".
        $this->assertMatchesRegularExpression('#<strong class="stock-low">\s*\d+ Rollen\s*</strong>#', self::request('?page=restock')['body']);
    }

    public function testRestockHintsAndTransferButton(): void
    {
        $bag1 = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 1'");
        $bag3 = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 3'");

        // Lagerort-Seite: was hier fehlt, mit Link zur passenden Stelle der Auffüllliste.
        $location = self::request('?page=location&id=' . $bag1)['body'];
        $this->assertStringContainsString('href="?page=restock#location-' . $bag1 . '"', $location);
        $this->assertMatchesRegularExpression('#\d+ Artikel fehl(t|en)#', $location);

        // Etwas, das im Hauptlager gar nicht vorrätig ist: kein Umbuchen-Button.
        self::$db->exec("INSERT INTO articles (article_number, name, unit_id) VALUES ('nicht-da', 'Nicht vorrätig', 1)");
        self::$db->exec("INSERT INTO article_location_minimums (article_id, location_id, minimum_stock) VALUES ((SELECT id FROM articles WHERE article_number = 'nicht-da'), $bag3, 2)");

        $restock = self::request('?page=restock')['body'];

        $this->assertStringContainsString('id="location-' . $bag3 . '"', $restock);
        $this->assertStringNotContainsString('target=' . $bag3 . '"', $restock);
        $this->assertStringContainsString('target=' . $bag1 . '"', $restock);

        self::$db->exec("DELETE FROM article_location_minimums WHERE location_id = $bag3");
        self::$db->exec("UPDATE articles SET active = 0 WHERE article_number = 'nicht-da'");
    }

    public function testPacklistAndInventoryOfLocation(): void
    {
        $bag = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 1'");
        $article = self::id("SELECT id FROM articles WHERE article_number = 'diag-bz-streifen'");

        $location = self::request('?page=location&id=' . $bag)['body'];
        $this->assertStringContainsString('href="?page=packlist&id=' . $bag . '"', $location);
        $this->assertStringContainsString('href="?page=inventory&id=' . $bag . '"', $location);

        // Packliste: Soll/Ist je Artikel, Druckbereich, Kästchen zum Abhaken.
        $packlist = self::request('?page=packlist&id=' . $bag)['body'];
        $this->assertStringContainsString('class="packlist"', $packlist);
        $this->assertStringContainsString('class="packlist-check"', $packlist);
        $this->assertStringContainsString('Heftpflaster', $packlist);

        // Inventur: Eingabefelder mit dem erwarteten Bestand vorbelegt.
        $inventory = self::request('?page=inventory&id=' . $bag)['body'];
        $this->assertMatchesRegularExpression('#name="count\[' . $article . '\]\[(\d+|none)\]"[^>]*value="\d+"#', $inventory);

        preg_match('#name="count\[' . $article . '\]\[(\d+|none)\]"[^>]*value="(\d+)"#', $inventory, $field);
        $expected = (int) $field[2];

        $response = self::request('?page=inventory&id=' . $bag, [
            'action' => 'inventory',
            'location_id' => (string) $bag,
            'count' => [(string) $article => [$field[1] => (string) ($expected + 1)]],
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('page=location&id=' . $bag, (string) $response['location']);

        $after = self::request('?page=location&id=' . $bag)['body'];
        $this->assertCleanPage(['status' => 200, 'body' => $after], 'Lagerort nach Inventur');
        $this->assertStringContainsString('1 Abweichung korrigiert', $after);
    }

    public function testExpiredScanRaisesAlarmAndCanBeSortedOut(): void
    {
        $page = self::request('?page=issue')['body'];
        $this->assertStringContainsString('<dialog', $page);
        $this->assertStringContainsString('id="issue-alarm-sort-out"', $page);
        $this->assertStringContainsString('id="issue-sound"', $page);

        $main = self::id("SELECT id FROM storage_locations WHERE name = 'Hauptlager'");
        self::$db->exec("INSERT INTO articles (article_number, name, unit_id) VALUES ('alarm-test', 'Alarmtest', 1)");
        $article = self::id("SELECT id FROM articles WHERE article_number = 'alarm-test'");
        self::$db->exec("INSERT INTO batches (article_id, expiry_date) VALUES ($article, '2020-01-31')");
        $batch = self::id("SELECT id FROM batches WHERE article_id = $article");
        self::$db->exec("INSERT INTO stock_movements (article_id, batch_id, location_id, quantity, movement_type) VALUES ($article, $batch, $main, 3, 'receipt')");

        $scan = json_decode(self::request('', [
            'action' => 'issue', 'ajax' => '1', 'article_number' => 'alarm-test', 'source' => (string) $main,
        ])['body'], true);

        $this->assertSame([['batch_id' => $batch, 'expiry_date' => '31.01.2020', 'quantity' => 1, 'remaining' => 2]], $scan['expired_batches']);

        $sortOut = self::request('', [
            'action' => 'sort_out_expired', 'ajax' => '1', 'article_id' => (string) $article,
            'source' => $scan['source'], 'target' => $scan['target'], 'batches' => [(string) $batch => '1'],
        ]);

        $this->assertSame(200, $sortOut['status'], $sortOut['body']);
        $this->assertStringContainsString('3 Stück aus Hauptlager entsorgt', json_decode($sortOut['body'], true)['message']);

        self::$db->exec("UPDATE articles SET active = 0 WHERE id = $article");
    }

    public function testSingleLabelPrintsEightLabelsOfOneArticle(): void
    {
        $body = self::request(self::resolve('?page=label&id={article}'))['body'];

        $this->assertSame(1, substr_count($body, 'class="label-print-page"'));
        $this->assertSame(8, substr_count($body, '<div class="label">'));
        $this->assertSame(8, substr_count($body, '<div class="label-number">diag-bz-streifen</div>'));
    }

    public function testCollectiveLabelsSelectionAndSheets(): void
    {
        $diagnostics = self::id("SELECT id FROM article_categories WHERE name = 'Diagnostik'");
        $inCategory = self::id('SELECT COUNT(*) FROM articles WHERE active = 1 AND category_id = ' . $diagnostics);
        $article = self::id("SELECT id FROM articles WHERE article_number = 'diag-bz-streifen'");
        $other = self::id("SELECT MIN(id) FROM articles WHERE active = 1 AND id <> " . $article);

        // Aus der Artikelliste mit Kategorie: deren Artikel je 1× vorausgewählt.
        $body = self::request('?page=labels&category=' . $diagnostics)['body'];
        $this->assertSame($inCategory, preg_match_all('#class="labels-quantity"[^>]*value="1"#s', $body));
        $this->assertStringContainsString('href="?page=labels&category=' . $diagnostics . '"', self::request('?page=articles&category=' . $diagnostics)['body']);

        // Auswahl: "alle 1×" / "keine" für alle Kategorien auf einmal.
        $body = self::request('?page=labels')['body'];
        $this->assertStringContainsString('data-label-all="1"', $body);
        $this->assertStringContainsString('data-label-all=""', $body);

        // 6 + 1 Etiketten → 1 Bogen, der Rest bleibt am Ende frei. Freie
        // Plätze am Anfang (angebrochene Bögen) gibt es nicht mehr – ein
        // altes `skip` in der Adresse wird nicht beachtet.
        $response = self::request('?page=labels&print=1&skip=3&qty[' . $article . ']=6&qty[' . $other . ']=1');
        $this->assertCleanPage($response, 'Sammeletiketten');
        $body = $response['body'];

        $this->assertMatchesRegularExpression('#7\s+Etiketten\s+auf 1\s+Bogen#', $body);
        $this->assertSame(1, substr_count($body, 'class="label-print-page"'));
        $this->assertSame(7, substr_count($body, '<div class="label">'));
        $this->assertSame(6, substr_count($body, '<div class="label-number">diag-bz-streifen</div>'));
        $this->assertSame(1, substr_count($body, 'label-empty'), 'nur am Ende frei');
        $this->assertGreaterThan(strpos($body, '<div class="label">'), strpos($body, 'label-empty'));
        $this->assertStringNotContainsString('skip', $body);

        // Nichts ausgewählt: Hinweis statt leerer Druckansicht.
        $response = self::request('?page=labels&print=1&qty[' . $article . ']=0');
        $this->assertCleanPage($response, 'Sammeletiketten ohne Auswahl');
        $this->assertStringContainsString('Bitte bei mindestens einem Artikel eine Anzahl eintragen.', $response['body']);
        $this->assertStringNotContainsString('class="label-print-page"', $response['body']);

        // Manipulierte Werte: begrenzt bzw. ignoriert.
        $response = self::request('?page=labels&print=1&skip=99&qty[' . $article . ']=500&qty[x]=abc&qty[' . $other . '][]=1');
        $this->assertCleanPage($response, 'Sammeletiketten mit ungültigen Werten');
        $this->assertMatchesRegularExpression('#99\s+Etiketten\s+auf 13\s+Bögen#', $response['body'], '99 Plätze');
    }

    public function testNewArticleKeepsCategory(): void
    {
        $category = self::id("SELECT id FROM article_categories WHERE name = 'Diagnostik'");

        $body = self::request('?page=new_article&category=' . $category)['body'];

        $this->assertMatchesRegularExpression('#value="' . $category . '"\s+data-short-name="[^"]*"\s+selected#', $body);
        $this->assertStringContainsString('Anlegen &amp; nächster Artikel', $body);
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

    public function testReceiptWithQuantityOnBookingPageKeepsExpiry(): void
    {
        $bag = self::id("SELECT id FROM storage_locations WHERE name = 'Rucksack 3'");
        $expiry = date('Y-m-d', strtotime('+4 years'));

        $response = self::request('?page=issue', [
            'action' => 'issue',
            'article_number' => 'verb-mullbinde-8',
            'source' => 'receipt',
            'target' => $bag,
            'quantity' => '3',
            'expiry_date' => $expiry,
        ]);

        $this->assertSame(302, $response['status']);

        $page = self::request((string) $response['location']);

        $this->assertCleanPage($page, 'nach Einlagern');
        $this->assertStringContainsString('Mullbinde 8 cm – 3 Stück eingelagert in Rucksack 3', $page['body']);
        $this->assertStringContainsString('Einlagern in Rucksack 3', $page['body']);
        $this->assertMatchesRegularExpression('#<option value="receipt" selected>#', $page['body']);
        $this->assertStringContainsString('value="' . $expiry . '"', $page['body'], 'MHD bleibt stehen');
        $this->assertMatchesRegularExpression('#id="issue-quantity"\s+value="1"#', $page['body'], 'Menge wieder 1');
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

    public function testArticleListShowsWhetherArticleHasExpiry(): void
    {
        $list = self::request('?page=articles')['body'];

        $this->assertMatchesRegularExpression('#<th>\s*MHD\s*</th>#', $list);
        $this->assertMatchesRegularExpression('#Mullbinde 8 cm.*?<td class="article-expiry-cell">\s*nein\s*</td>#s', $list);
        $this->assertMatchesRegularExpression('#Blutzuckermessstreifen.*?<td class="article-expiry-cell">\s*ja\s*</td>#s', $list);
    }

    public function testDisposeUndoAndArticleBooking(): void
    {
        $articleId = self::id("SELECT id FROM articles WHERE article_number = 'hyg-wundantiseptikum'");
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
        $disposals = substr_count($today['body'], 'value="undo_disposal"');
        $this->assertGreaterThan(0, $disposals);
        $this->assertMatchesRegularExpression('#href="\#heute-entsorgt"[^>]*>\s*<strong>\s*' . $disposals . '\s*</strong>\s*<span>\s*' . ($disposals === 1 ? 'Entsorgung' : 'Entsorgungen') . '\s*</span>#', $today['body']);
        $this->assertMatchesRegularExpression('#<h2[^>]*id="heute-entsorgt"[^>]*>\s*Heute entsorgt#', $today['body']);

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
            // Relativ zu heute, sonst irgendwann "abgelaufen" (Rückfrage).
            'expiry_date' => date('d.m.Y', strtotime('+2 years')),
            'quantity' => 2,
        ]);

        $this->assertSame(302, $move['status']);

        $article = self::request((string) $move['location']);
        $this->assertCleanPage($article, 'Artikel nach Einlagern');
        $this->assertStringContainsString('2 Flaschen eingelagert in Hauptlager', $article['body']);
        $this->assertStringContainsString('MHD: ' . date('d.m.Y', strtotime('+2 years')), $article['body']);
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

    /**
     * Manipulierte Adressen und Formulare (Array statt Text) zeigen die
     * normale Seite bzw. eine Meldung, keinen technischen Fehler.
     */
    public function testArrayParametersDoNotCauseErrors(): void
    {
        $this->assertCleanPage(self::request('?page=articles&search[]=x'), 'Suche als Array');
        $this->assertCleanPage(self::request('?page[]=articles'), 'Seite als Array');
        $this->assertCleanPage(
            self::request('?page=issue', ['action' => ['issue'], 'article_number' => 'x']),
            'Aktion als Array'
        );
    }
}
