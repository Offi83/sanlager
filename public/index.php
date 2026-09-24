<?php

/*
|--------------------------------------------------------------------------
| SanLager – zentraler Einstiegspunkt
|--------------------------------------------------------------------------
|
| Bewusst ohne Framework/Router. Ablauf pro Request:
|
|   1. POST-Aktionen – $action wird an die *Actions-Klassen aus src/
|                      weitergereicht (siehe unten); Fehler werden als
|                      $error angezeigt.
|   2. Seitendaten   – pages/<seite>.php lädt die Daten für $page (dort
|                      sind auch noch Weiterleitungen möglich).
|   3. HTML          – templates/layout/header.php, dann die Vorlage
|                      templates/pages/<seite>.php, dann
|                      templates/layout/footer.php.
|
| Die eigentliche Datenbank- und Geschäftslogik liegt in den Klassen
| unter src/:
|   *Repository.php  – reiner Datenbankzugriff je Tabelle/Bereich
|   *Actions.php     – Validierung + Verarbeitung der POST-Aktionen
|   helpers.php      – globale Helper (h(), redirect(), formatDate(), ...)
| Bausteine der Vorlagen (Entsorgen-/Rückgängig-Button) liegen in
| templates/helpers.php.
|--------------------------------------------------------------------------
*/

use LagerApp\ArticleActions;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryActions;
use LagerApp\CategoryRepository;
use LagerApp\LocationActions;
use LagerApp\LocationRepository;
use LagerApp\StockActions;
use LagerApp\StockReports;
use LagerApp\StockRepository;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Auffangnetz für unerwartete Fehler (z. B. Datenbank nicht erreichbar):
 * keine technischen Details anzeigen, sondern protokollieren, siehe
 * userMessage().
 */
set_exception_handler(static function (Throwable $exception): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<p style="font-family: sans-serif; padding: 24px;">'
        . h(userMessage($exception))
        . ' <a href="?">Zur Startseite</a></p>';
});

/*
 * .env, Zeitzone und Datenbank – gemeinsam mit den Skripten unter bin/.
 */
$db = require __DIR__ . '/../bootstrap.php';

/*
 * Bausteine der Seitenvorlagen (Entsorgen-/Rückgängig-Buttons).
 */
require __DIR__ . '/../templates/helpers.php';

/*
 * Session nur für Meldungen nach einer Aktion (siehe flash()).
 */
startSession();

$articles = new ArticleRepository($db);
$batches = new BatchRepository($db);
$categories = new CategoryRepository($db);
$locationRepository = new LocationRepository($db);
$stock = new StockRepository($db);
$reports = new StockReports($db);

/*
 * h(), redirect(), formatDate() und expiryInfo() sind globale
 * Helper-Funktionen aus src/helpers.php, die per "files"-Autoload-Eintrag
 * in composer.json automatisch geladen werden.
 */

$articleActions = new ArticleActions($articles, $categories, $stock);
$categoryActions = new CategoryActions($categories);
$locationActions = new LocationActions($locationRepository, $stock);
$stockActions = new StockActions($articles, $locationRepository, $stock, $batches);

$categoryList = $categories->all();

$page = $_GET['page'] ?? 'issue';
$action = $_POST['action'] ?? null;

$error = null;
$message = null;
$messageType = 'success';

/*
|--------------------------------------------------------------------------
| POST-Aktionen
|--------------------------------------------------------------------------
|
| Die eigentliche Validierung und Verarbeitung liegt in den *Actions-
| Klassen unter src/. Jede dispatch()-Methode kümmert sich nur um die
| Aktionen, für die sie zuständig ist, und liefert für alle anderen null.
| Die zuständige Klasse gibt ein ActionResult (Redirect oder JSON) zurück,
| das erst hier per send() ausgegeben wird.
| Fehler werden hier zentral abgefangen und als $error angezeigt – Eingabe-
| fehler im Klartext, technische Fehler nur allgemein (siehe userMessage()).
|
| Vorher wird geprüft, dass die Anfrage von dieser Anwendung stammt
| (Schutz vor Cross-Site Request Forgery, siehe isSameOriginRequest()).
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isSameOriginRequest($_SERVER)) {

    $rejected = 'Anfrage abgelehnt: Sie stammt nicht von dieser Anwendung. '
        . 'Bitte die Seite neu laden und noch einmal versuchen.';

    /*
     * Scanner (ajax=1) und Drag & Drop (reorder_*) erwarten JSON.
     */
    if (($_POST['ajax'] ?? '') === '1' || str_starts_with((string) $action, 'reorder_')) {
        LagerApp\ActionResult::json(['success' => false, 'error' => $rejected], 403)->send();
    }

    http_response_code(403);
    $error = $rejected;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $result = $articleActions->dispatch($action, $_POST)
            ?? $categoryActions->dispatch($action, $_POST)
            ?? $locationActions->dispatch($action, $_POST)
            ?? $stockActions->dispatch($action, $_POST);

        $result?->send();

    } catch (Throwable $exception) {

        $error = userMessage($exception);
    }
}

/*
 * Meldung der vorherigen Aktion (Redirect-nach-POST). Kommt aus der
 * Session, nicht aus der Adresse – so lässt sich kein fremder Text als
 * Systemmeldung unterschieben.
 */
$flash = takeFlash();

if ($flash !== null) {
    $message = $flash['text'];
    $messageType = $flash['type'] === 'error' ? 'error' : 'success';
}

/*
|--------------------------------------------------------------------------
| Daten für die jeweilige Seite (pages/<seite>.php)
|--------------------------------------------------------------------------
|
| Unbekannte Seiten zeigen die Buchen-Seite. Nicht jede Seite braucht
| eine Datendatei (z. B. new_article).
*/
$pageNames = [
    'issue', 'today_issues', 'expiry', 'restock', 'articles', 'article', 'label',
    'new_article', 'edit_article', 'categories', 'locations', 'location',
];

if (!in_array($page, $pageNames, true)) {
    $page = 'issue';
}

if (is_file(__DIR__ . '/../pages/' . $page . '.php')) {
    require __DIR__ . '/../pages/' . $page . '.php';
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/

/*
 * Zahlen für die Navigation (siehe templates/layout/header.php): wo in
 * "Kontrolle" etwas zu tun ist.
 */
$navCounts = [
    'expiry' => $reports->countExpiredBatches(),
    'restock' => $restockItemCount ?? count($reports->getLowStockItems()),
];

require __DIR__ . '/../templates/layout/header.php';
require __DIR__ . '/../templates/pages/' . $page . '.php';
require __DIR__ . '/../templates/layout/footer.php';
