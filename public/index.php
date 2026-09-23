<?php

/*
|--------------------------------------------------------------------------
| SanLager – zentraler Einstiegspunkt
|--------------------------------------------------------------------------
|
| Bewusst als einzelne Datei gehalten (kein Framework/Router). Ablauf pro
| Request:
|
|   1. POST-Aktionen   – $action wird an die *Actions-Klassen aus src/
|                        weitergereicht (siehe unten). Ungültige Eingaben
|                        werfen eine RuntimeException, die unten als
|                        $error abgefangen und angezeigt wird.
|   2. GET-Datenaufbau – $page bestimmt, welche Daten für die jeweilige
|                        Seite aus den Repositories geladen werden.
|   3. HTML             – ein großer if/elseif-Block anhand von $page.
|
| Die eigentliche Datenbank- und Geschäftslogik liegt in den Klassen
| unter src/, hier wird nur verknüpft und dargestellt:
|   *Repository.php – reiner Datenbankzugriff je Tabelle/Bereich
|   *Actions.php     – Validierung + Verarbeitung der POST-Aktionen
|   helpers.php       – globale Helper (h(), redirect(), formatDate(), ...)
|--------------------------------------------------------------------------
*/

use LagerApp\ArticleActions;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryActions;
use LagerApp\CategoryRepository;
use LagerApp\LocationActions;
use LagerApp\LocationRepository;
use LagerApp\QrCodeGenerator;
use LagerApp\StockActions;
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
 * Session nur für Meldungen nach einer Aktion (siehe flash()).
 */
startSession();

$articles = new ArticleRepository($db);
$batches = new BatchRepository($db);
$categories = new CategoryRepository($db);
$locationRepository = new LocationRepository($db);
$stock = new StockRepository($db);

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
| Daten für die jeweilige Seite
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$articleList = [];
$selectedCategoryId = null;

if ($page === 'categories') {

    $categoryList = $categories->all();
}

$locationList = [];

if ($page === 'locations') {

    $locationList = $locationRepository->all();
}

if ($page === 'articles') {

    $categoryParam = (int) ($_GET['category'] ?? 0);

    if ($categoryParam > 0 && $categories->find($categoryParam)) {
        $selectedCategoryId = $categoryParam;
    }

    $articleList = $articles->all(
        $search,
        $selectedCategoryId
    );

    /*
     * Bestand, abgelaufene Menge und Mindestbestand-Warnung für alle
     * Artikel mit zwei Abfragen statt drei Abfragen je Artikel.
     */
    $articleStockSummaries = $stock->getStockSummaries();
}

$article = null;
$articleStock = [];
$articleBatches = [];
$articleBatchesByLocation = [];
$locations = [];
$todayIssues = [];
$todayIssueCount = 0;
$todayTransfers = [];
$todayDisposedCount = 0;
$editCategory = null;

if ($page === 'categories' && isset($_GET['edit'])) {

    $editCategoryId = (int) $_GET['edit'];

    if ($editCategoryId > 0) {
        $editCategory = $categories->find($editCategoryId);
    }
}

$editLocation = null;
$editLocationHasStock = false;
$transferTargetLocations = [];

if ($page === 'locations' && isset($_GET['edit'])) {

    $editLocationId = (int) $_GET['edit'];

    if ($editLocationId > 0) {
        $editLocation = $locationRepository->find($editLocationId);
    }

    if ($editLocation) {

        $editLocationHasStock = $stock->locationHasStock(
            (int) $editLocation['id']
        );

        $transferTargetLocations = array_values(array_filter(
            $locationRepository->all(),
            static fn (array $location): bool =>
                (int) $location['id'] !== (int) $editLocation['id']
        ));
    }
}

if ($page === 'today_issues') {

    $todayIssueCount = $stock->getTodayIssueCount();
    $todayTransfers = $stock->getTodayTransfers();

    /*
     * Entsorgungen erscheinen in derselben Liste wie die Ausbuchungen
     * (als "entsorgt" gekennzeichnet), zählen aber nicht zur Zahl der
     * Ausbuchungen – Entsorgen ist kein Verbrauch.
     */
    $todayIssues = array_merge(
        array_map(
            static fn (array $row): array => $row + ['kind' => 'issue'],
            $stock->getTodayIssues()
        ),
        array_map(
            static fn (array $row): array => $row + ['kind' => 'disposal'],
            $stock->getTodayDisposals()
        )
    );

    usort(
        $todayIssues,
        static fn (array $a, array $b): int =>
            strcasecmp($a['article_name'], $b['article_name'])
            ?: strcmp((string) $a['expiry_date'], (string) $b['expiry_date'])
    );

    $todayDisposedCount = array_sum(array_map(
        static fn (array $row): int => $row['kind'] === 'disposal' ? (int) $row['quantity'] : 0,
        $todayIssues
    ));
}

$viewLocation = null;
$locationStockRows = [];

if ($page === 'location') {

    $viewLocationId = (int) ($_GET['id'] ?? 0);

    if ($viewLocationId <= 0) {
        redirect('?page=locations');
    }

    $viewLocation = $locationRepository->find($viewLocationId);

    if (!$viewLocation) {
        redirect('?page=locations');
    }

    $locationStockRows = $stock->getStockAtLocationDetailed(
        $viewLocationId
    );
}

$expiringBatches = [];
$expiredCount = 0;
$expiringSoonCount = 0;

if ($page === 'expiry') {

    $expiringBatches = $stock->getExpiringBatches(90);

    foreach ($expiringBatches as $row) {

        $rowExpiry = expiryInfo($row['expiry_date']);

        if ($rowExpiry['class'] === 'expiry-expired') {
            $expiredCount++;
        } elseif ($rowExpiry['class'] === 'expiry-warning') {
            $expiringSoonCount++;
        }
    }
}

/**
 * Button "Entsorgen" für eine abgelaufene Charge an einem Lagerort
 * (MHD-Übersicht, Artikel- und Lagerort-Detailseite). Entnimmt nach
 * Rückfrage die komplette Menge, siehe StockActions::disposeBatch().
 *
 * @param string $return Seite, auf die danach zurückgeleitet wird:
 *                       expiry|article|location
 */
function renderDisposeForm(
    int $articleId,
    int $batchId,
    int $locationId,
    string $question,
    string $return
): string {
    return '<form method="post" class="dispose-form" onsubmit="return confirm('
        . h(json_encode($question, JSON_UNESCAPED_UNICODE)) . ');">'
        . '<input type="hidden" name="action" value="dispose_batch">'
        . '<input type="hidden" name="article_id" value="' . $articleId . '">'
        . '<input type="hidden" name="batch_id" value="' . $batchId . '">'
        . '<input type="hidden" name="location_id" value="' . $locationId . '">'
        . '<input type="hidden" name="return" value="' . h($return) . '">'
        . '<button type="submit" class="button button-danger small icon-button"'
        . ' title="Entsorgen" aria-label="Entsorgen">' . icon('trash') . '</button>'
        . '</form>';
}

/**
 * Rückgängig-Button (Pfeil) für eine Zeile auf "Heute ausgebucht": nimmt
 * nach Rückfrage die ganze Zeile zurück. Gegenbuchung statt Löschen,
 * siehe StockActions::undoToday().
 *
 * @param string $action undo_issue|undo_transfer|undo_disposal
 * @param array<string, int|null> $fields versteckte Felder (article_id, batch_id, ...)
 * @param string $question Rückfrage, z. B. "3 Stück Mullbinde zurück nach Hauptlager buchen?"
 */
function renderUndoForm(
    string $action,
    array $fields,
    int $quantity,
    string $question
): string {
    $html = '<form method="post" onsubmit="return confirm('
        . h(json_encode($question, JSON_UNESCAPED_UNICODE))
        . ');">'
        . '<input type="hidden" name="action" value="' . h($action) . '">';

    foreach ($fields + ['quantity' => $quantity] as $name => $value) {
        $html .= '<input type="hidden" name="' . h($name) . '" value="' . (int) $value . '">';
    }

    return $html
        . '<button type="submit" class="button button-secondary small icon-button"'
        . ' title="Rückgängig" aria-label="Rückgängig">' . icon('undo') . '</button>'
        . '</form>';
}

$allLocations = [];

if ($page === 'issue') {

    /*
     * Sowohl "Von" als auch "Nach" bekommen die volle Liste; welche
     * Kombination gültig ist (Von != Nach), steuert das Frontend
     * (issue-source-Auswahl blendet die gleiche Option im Nach-Select
     * aus) und wird zusätzlich serverseitig in StockActions geprüft.
     */
    $allLocations = $locationRepository->all();

    /*
     * Von/Nach der vorherigen Buchung beibehalten: nach Erfolg kommen
     * sie über den Redirect (GET, siehe StockActions::issue()), nach
     * einem Fehler aus dem abgeschickten Formular (POST). Ungültige oder
     * inzwischen deaktivierte Lagerorte fallen auf die Standardauswahl
     * (erster Lagerort der Sortierung, Ausbuchen) zurück.
     */
    $requestValue = static fn (string $key): string =>
        is_string($_POST[$key] ?? null)
            ? $_POST[$key]
            : (is_string($_GET[$key] ?? null) ? $_GET[$key] : '');

    $locationNamesById = array_column($allLocations, 'name', 'id');

    $issueSourceId = (int) $requestValue('source');

    if (!isset($locationNamesById[$issueSourceId])) {
        $issueSourceId = (int) array_key_first($locationNamesById);
    }

    $issueTarget = $requestValue('target');

    if (
        $issueTarget !== 'issue'
        && (
            !isset($locationNamesById[(int) $issueTarget])
            || (int) $issueTarget === $issueSourceId
        )
    ) {
        $issueTarget = 'issue';
    }

    $issueModeText = $issueTarget === 'issue'
        ? 'Ausbuchen aus ' . ($locationNamesById[$issueSourceId] ?? '')
        : 'Umbuchen: ' . ($locationNamesById[$issueSourceId] ?? '')
            . ' → ' . $locationNamesById[(int) $issueTarget];
}

if ($page === 'article' || $page === 'label') {

    $articleId = (int) ($_GET['id'] ?? 0);

    if ($articleId <= 0) {
        redirect('?page=articles');
    }

    $article = $articles->find($articleId);

    if (!$article) {
        redirect('?page=articles');
    }

    if ($page === 'article') {

        $articleStock = $stock->getStockForArticle(
            $articleId
        );

        $articleBatches = $stock->getStockByBatch(
            $articleId
        );

        $articleBatchesByLocation = $stock->getStockByBatchAndLocation(
            $articleId
        );

        $locations = $stock->locations();
    }
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/
?>
<!DOCTYPE html>
<html lang="de">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>SanLager</title>

    <link
        rel="stylesheet"
        href="/css/app.css"
    >

</head>

<body>

<header class="topbar">

    <div class="topbar-inner">

<a href="?page=issue" class="brand">
    <img
        src="/images/pflaster.svg"
        alt="DRK Bereitschaften"
    >
    <div class="brand-text">
        <strong>SanLager</strong>
        <span>Materialverwaltung</span>
    </div>
</a>

<nav>
    <a href="?page=issue" class="<?= $page === 'issue' ? 'active' : '' ?>">
        Buchen
    </a>
    <a href="?page=today_issues" class="<?= $page === 'today_issues' ? 'active' : '' ?>">
        Heute ausgebucht
    </a>
    <a href="?page=expiry" class="<?= $page === 'expiry' ? 'active' : '' ?>">
        MHD-Übersicht
    </a>
    <a href="?page=articles" class="<?= $page === 'articles' ? 'active' : '' ?>">
        Artikel
    </a>
    <a href="?page=categories" class="<?= $page === 'categories' ? 'active' : '' ?>">
        Kategorien
    </a>
    <a href="?page=locations" class="<?= $page === 'locations' ? 'active' : '' ?>">
        Lagerorte
    </a>
</nav>

    </div>

</header>

<main class="container">

    <?php if ($message): ?>

        <div class="alert <?= $messageType ?>">
            <?= h($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert error">
            <?= h($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($page === 'categories'): ?>

        <div class="page-header">

            <div>

                <h1>Kategorien</h1>

                <p>
                    Kategorien für die Artikelverwaltung verwalten und sortieren.
                </p>

            </div>

        </div>


        <div class="category-layout">

            <section class="card">

                <div class="card-header">

                    <h2>
                        <?= $editCategory ? 'Kategorie bearbeiten' : 'Neue Kategorie' ?>
                    </h2>

                </div>

                <form method="post" class="form">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editCategory ? 'update_category' : 'create_category' ?>"
                    >

                    <?php if ($editCategory): ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $editCategory['id'] ?>"
                        >

                    <?php endif; ?>


                    <label>

                        <span>Name</span>

                        <input
                            type="text"
                            name="name"
                            required
                            maxlength="100"
                            value="<?= h($editCategory['name'] ?? '') ?>"
                            placeholder="z. B. Verbandmaterial"
                        >

                    </label>


                    <label>

                        <span>Kürzel</span>

                        <input
                            type="text"
                            name="short_name"
                            required
                            maxlength="20"
                            value="<?= h($editCategory['short_name'] ?? '') ?>"
                            placeholder="z. B. VM"
                        >

                    </label>


                    <label>

                        <span>Farbe</span>

                        <div class="category-color-input">

                            <input
                                type="color"
                                name="color"
                                value="<?= h($editCategory['color'] ?? '#d71920') ?>"
                                title="Kategorie-Farbe auswählen"
                            >

                            <span>
                                Farbe der Kategorie
                            </span>

                        </div>

                    </label>


                    <div class="form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editCategory ? 'Kategorie speichern' : 'Kategorie anlegen' ?>
                        </button>

                        <?php if ($editCategory): ?>

                            <a
                                href="?page=categories"
                                class="button button-secondary"
                            >
                                Abbrechen
                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>


            <section class="card">

                <div class="card-header">

                    <h2>Kategorien</h2>

                </div>

                <div class="card-body">

                    <p class="form-help">
                        Kategorien per Drag &amp; Drop in die gewünschte Reihenfolge ziehen.
                    </p>


                    <div
                        id="category-list"
                        class="category-list"
                        data-sortable-list
                        data-sortable-row=".category-row"
                        data-sortable-id-attribute="categoryId"
                        data-sortable-action="reorder_categories"
                    >

                        <?php foreach ($categoryList as $category): ?>

                            <div
                                class="category-row"
                                draggable="true"
                                data-category-id="<?= (int) $category['id'] ?>"
                            >

                                <div class="category-drag">
                                    ⋮⋮
                                </div>


                                <div
                                    class="category-color"
                                    style="background-color: <?= h($category['color']) ?>"
                                ></div>


                                <div class="category-info">

                                    <strong>
                                        <?= h($category['name']) ?>
                                    </strong>

                                    <span>
                                        <?= h($category['short_name']) ?>
                                    </span>

                                </div>


                                <div class="category-actions">

                                    <a
                                        href="?page=categories&edit=<?= (int) $category['id'] ?>"
                                        class="button button-secondary small"
                                    >
                                        Bearbeiten
                                    </a>


                                    <form
                                        method="post"
                                        onsubmit="return confirm('Kategorie wirklich löschen?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete_category"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $category['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button button-danger small"
                                        >
                                            Löschen
                                        </button>

                                    </form>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </section>

        </div>




    <?php elseif ($page === 'locations'): ?>

        <div class="page-header">

            <div>

                <h1>Lagerorte</h1>

                <p>
                    Lagerorte für die Bestandsverwaltung anlegen, bearbeiten
                    und sortieren.
                </p>

            </div>

        </div>


        <div class="category-layout">

            <section class="card">

                <div class="card-header">

                    <h2>
                        <?= $editLocation ? 'Lagerort bearbeiten' : 'Neuer Lagerort' ?>
                    </h2>

                </div>

                <form method="post" class="form">

                    <input
                        type="hidden"
                        name="action"
                        value="<?= $editLocation ? 'update_location' : 'create_location' ?>"
                    >

                    <?php if ($editLocation): ?>

                        <input
                            type="hidden"
                            name="id"
                            value="<?= (int) $editLocation['id'] ?>"
                        >

                    <?php endif; ?>

                    <label>

                        <span>Name</span>

                        <input
                            type="text"
                            name="name"
                            required
                            maxlength="100"
                            value="<?= h($editLocation['name'] ?? '') ?>"
                            placeholder="z. B. Hauptlager"
                        >

                    </label>

                    <label>

                        <span>Beschreibung</span>

                        <textarea
                            name="description"
                            rows="2"
                            placeholder="Optional"
                        ><?= h($editLocation['description'] ?? '') ?></textarea>

                    </label>

                    <div class="form-actions">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            <?= $editLocation ? 'Lagerort speichern' : 'Lagerort anlegen' ?>
                        </button>

                        <?php if ($editLocation): ?>

                            <a
                                href="?page=locations"
                                class="button button-secondary"
                            >
                                Abbrechen
                            </a>

                        <?php endif; ?>

                    </div>

                </form>

            </section>


            <section class="card">

                <div class="card-header">

                    <h2>Lagerorte</h2>

                </div>

                <div class="card-body">

                    <p class="form-help">
                        Lagerorte per Drag &amp; Drop in die gewünschte Reihenfolge ziehen.
                        Diese Reihenfolge bestimmt auch die Auswahl beim Buchen;
                        der oberste Lagerort ist dort vorausgewählt.
                    </p>

                    <?php if (!$locationList): ?>

                        <div class="empty-state compact">
                            Noch keine Lagerorte vorhanden.
                        </div>

                    <?php else: ?>

                        <div
                            id="location-list"
                            class="category-list"
                            data-sortable-list
                            data-sortable-row=".location-sort-row"
                            data-sortable-id-attribute="locationId"
                            data-sortable-action="reorder_locations"
                        >

                            <?php foreach ($locationList as $location): ?>

                                <div
                                    class="location-sort-row"
                                    draggable="true"
                                    data-location-id="<?= (int) $location['id'] ?>"
                                >

                                    <div class="category-drag">
                                        ⋮⋮
                                    </div>

                                    <div class="location-sort-info">

                                        <a href="?page=location&id=<?= (int) $location['id'] ?>">
                                            <strong>
                                                <?= h($location['name']) ?>
                                            </strong>
                                        </a>

                                        <?php if (!empty($location['description'])): ?>

                                            <span>
                                                <?= h($location['description']) ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <div class="category-actions">

                                        <a
                                            href="?page=locations&edit=<?= (int) $location['id'] ?>"
                                            class="button button-secondary small"
                                        >
                                            Bearbeiten
                                        </a>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Lagerort wirklich deaktivieren?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="deactivate_location"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $location['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="button button-danger small"
                                            >
                                                Deaktivieren
                                            </button>

                                        </form>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </div>

        <?php if ($editLocation): ?>

            <div class="card">

                <div class="card-header">
                    <h2>Bestand verschieben</h2>
                </div>

                <div class="card-body">

                    <?php if (!$editLocationHasStock): ?>

                        <p class="form-help">
                            Dieser Lagerort hat aktuell keinen Bestand
                            zum Verschieben.
                        </p>

                    <?php elseif (!$transferTargetLocations): ?>

                        <p class="form-help">
                            Es gibt keinen weiteren Lagerort, an den
                            der Bestand verschoben werden könnte.
                        </p>

                    <?php else: ?>

                        <p class="form-help">
                            Verschiebt den kompletten Bestand von
                            "<?= h($editLocation['name']) ?>" auf einen
                            anderen Lagerort – z. B. um eine Kiste nach
                            einem Dienst wieder vollständig zurück ins
                            Lager zu räumen, ohne jeden Artikel einzeln
                            umbuchen zu müssen.
                        </p>

                        <form
                            method="post"
                            class="form"
                            onsubmit="return confirm('Kompletten Bestand nach ' + this.to_location_id.options[this.to_location_id.selectedIndex].text + ' verschieben?');"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="transfer_all_stock"
                            >

                            <input
                                type="hidden"
                                name="from_location_id"
                                value="<?= (int) $editLocation['id'] ?>"
                            >

                            <label>

                                <span>Nach</span>

                                <select name="to_location_id">

                                    <?php foreach ($transferTargetLocations as $transferTargetLocation): ?>

                                        <option value="<?= (int) $transferTargetLocation['id'] ?>">
                                            <?= h($transferTargetLocation['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </label>

                            <div class="form-actions">

                                <button
                                    type="submit"
                                    class="button button-primary"
                                >
                                    Kompletten Bestand verschieben
                                </button>

                            </div>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    <?php elseif ($page === 'location' && $viewLocation): ?>

        <div class="page-header">

            <div>

                <a
                    href="?page=locations"
                    class="back-link"
                >
                    ← Lagerorte
                </a>

                <h1>
                    <?= h($viewLocation['name']) ?>
                </h1>

                <?php if ($viewLocation['description']): ?>

                    <p>
                        <?= h($viewLocation['description']) ?>
                    </p>

                <?php endif; ?>

            </div>

            <div class="actions">

                <a
                    href="?page=locations&edit=<?= (int) $viewLocation['id'] ?>"
                    class="button"
                >
                    Lagerort bearbeiten
                </a>

            </div>

        </div>

        <div class="card">

            <?php if (!$locationStockRows): ?>

                <div class="empty-state compact">
                    An diesem Lagerort ist aktuell kein Bestand
                    vorhanden.
                </div>

            <?php else: ?>

                <table class="table-with-article-number">

                    <thead>

                        <tr>
                            <th>Artikel</th>
                            <th>Artikelnummer</th>
                            <th>MHD</th>
                            <th>Bestand</th>
                            <th></th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php $currentLocationCategoryId = null; ?>

                        <?php foreach ($locationStockRows as $row): ?>

                            <?php
                            $rowCategoryId = $row['category_name'] !== null
                                ? $row['category_name']
                                : '';
                            ?>

                            <?php if ($currentLocationCategoryId !== $rowCategoryId): ?>

                                <?php $currentLocationCategoryId = $rowCategoryId; ?>

                                <tr
                                    class="article-category-row"
                                    style="background-color: <?= h($row['category_color'] ?? '#64748b') ?>;"
                                >
                                    <th colspan="5">
                                        <span class="article-category-name">
                                            <?= h($row['category_name'] ?? 'Ohne Kategorie') ?>
                                        </span>
                                    </th>
                                </tr>

                            <?php endif; ?>

                            <?php $rowExpiry = expiryInfo($row['expiry_date']); ?>

                            <tr>

                                <td>
                                    <a
                                        href="?page=article&id=<?= (int) $row['article_id'] ?>"
                                        class="article-link"
                                    >
                                        <strong>
                                            <?= h($row['article_name']) ?>
                                        </strong>
                                    </a>
                                </td>

                                <td>
                                    <?= h($row['article_number'] ?? '') ?>
                                </td>

                                <td>

                                    <span class="<?= $rowExpiry['class'] ?>">
                                        <?= $row['expiry_date']
                                            ? h(formatDate($row['expiry_date']))
                                            : 'ohne MHD' ?>
                                    </span>

                                    <?php if ($rowExpiry['warning']): ?>

                                        <span class="warning">
                                            <?= h($rowExpiry['warning']) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <strong>
                                        <?= (int) $row['quantity'] ?>
                                        <?= h($row['unit']) ?>
                                    </strong>
                                </td>

                                <td>

                                    <?php if ($rowExpiry['class'] === 'expiry-expired'): ?>

                                        <?= renderDisposeForm(
                                            (int) $row['article_id'],
                                            (int) $row['batch_id'],
                                            (int) $viewLocation['id'],
                                            $row['article_name'] . ': ' . (int) $row['quantity'] . ' ' . $row['unit']
                                                . ' (MHD ' . formatDate($row['expiry_date']) . ') aus '
                                                . $viewLocation['name'] . ' entsorgen?',
                                            'location'
                                        ) ?>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    <?php elseif ($page === 'articles'): ?>

        <div class="page-header">

            <div>
                <h1>Artikel</h1>

                <p>
                    Sanitätsmaterial und aktueller Bestand
                </p>
            </div>

            <a
                class="button button-primary"
                href="?page=new_article"
            >
                + Artikel anlegen
            </a>

        </div>

        <div class="card article-search-card">
        <div class="card-header"><h2>Suche Artikel</h2></div>
        <div class="card-body">
        <form
            method="get"
            class="search-form"
        >

            <input
                type="hidden"
                name="page"
                value="articles"
            >

            <input
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Artikel suchen ..."
                autofocus
            >

            <select
                name="category"
                class="category-filter"
            >

                <option value="0">
                    Alle Kategorien
                </option>

                <?php foreach ($categoryList as $category): ?>

                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= h($category['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                type="submit"
                class="button"
            >
                Suchen
            </button>

            <?php if ($search !== ''): ?>

                <a
                    href="?page=articles"
                    class="button button-secondary"
                >
                    Zurücksetzen
                </a>

            <?php endif; ?>

        </form>
        </div>
        </div>

        <div class="card">

            <?php if (!$articleList): ?>

                <div class="empty-state">

                    <h2>
                        Keine Artikel gefunden
                    </h2>

                    <p>
                        Legen Sie den ersten Artikel an.
                    </p>

                    <a
                        href="?page=new_article"
                        class="button button-primary"
                    >
                        Artikel anlegen
                    </a>

                </div>

            <?php else: ?>

                <table class="table-with-article-number">

                    <thead>

                    <tr>
                        <th>Artikel</th>
                        <th>Artikelnummer</th>
                        <th>Einheit</th>
                        <th>Bestand</th>
                    </tr>

                    </thead>

                    <tbody>

                    <?php
                    $currentCategoryId = null;
                    ?>

                    <?php foreach ($articleList as $item): ?>

                        <?php if (
                            $selectedCategoryId === null
                            && $currentCategoryId !== (int) ($item['category_id'] ?? 0)
                        ): ?>

                            <?php
                            $currentCategoryId = (int) ($item['category_id'] ?? 0);
                            ?>

                            <tr
                                class="article-category-row"
                                style="background-color: <?= h($item['category_color'] ?? '#64748b') ?>;"
                            >
                                <th colspan="4">
                                    <span class="article-category-name">
                                        <?= h($item['category_name'] ?? 'Ohne Kategorie') ?>
                                    </span>
                                </th>
                            </tr>

                        <?php endif; ?>

                        <?php
                        $summary = $articleStockSummaries[(int) $item['id']]
                            ?? StockRepository::EMPTY_SUMMARY;

                        $total = $summary['total'];
                        $expiredStock = $summary['expired'];
                        $isLow = $summary['is_low'];
                        ?>

                        <tr>

                            <td>

                                <a
                                    href="?page=article&id=<?= (int) $item['id'] ?>"
                                    class="article-link"
                                >
                                    <strong>
                                        <?= h($item['name']) ?>
                                    </strong>
                                </a>

                                <?php if ($item['description']): ?>

                                    <small>
                                        <?= h($item['description']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= h($item['article_number']) ?>
                            </td>

                            <td>
                                <?= h($item['unit']) ?>
                            </td>

                            <td class="article-stock-cell">

                                <a
                                    href="?page=article&id=<?= (int) $item['id'] ?>"
                                    class="article-stock-link"
                                >
                                    <strong class="<?= $isLow ? 'stock-low' : '' ?>">
                                        <?= $total ?>
                                    </strong>
                                </a>

                                <?php if ($expiredStock > 0): ?>

                                    <small class="expired-stock-warning">
                                        MHD: <?= $expiredStock ?> abgelaufen
                                    </small>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>


    <?php elseif ($page === 'today_issues'): ?>

        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>Heute ausgebucht</h1>

                    <p>
                        Übersicht aller heutigen Ausbuchungen, Umbuchungen und Entsorgungen –
                        mit Rückgängig.
                    </p>

                </div>

                <div class="actions">

                    <a
                        href="?page=today_issues"
                        class="button"
                    >
                        Aktualisieren
                    </a>

                </div>

            </div>

            <div class="card">

                <div class="today-summary">

                    <strong>
                        <?= $todayIssueCount ?>
                    </strong>

                    <span>
                        Ausbuchungen heute
                    </span>

                    <?php if ($todayDisposedCount > 0): ?>

                        <span class="today-disposed">
                            + <?= $todayDisposedCount ?> entsorgt
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <div class="card">

                <?php if (!$todayIssues): ?>

                    <p class="empty-state compact">
                        Heute wurden noch keine Artikel ausgebucht oder entsorgt.
                    </p>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="table-with-article-number">

                            <thead>

                                <tr>


                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Menge</th>
                                    <th>MHD</th>
                                    <th>Lagerort</th>
                                    <th>Rückgängig</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($todayIssues as $movement): ?>

                                    <tr>



                                        <td>
                                            <strong>
                                                <?= h($movement['article_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(
                                                $movement['article_number']
                                                    ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= (int) $movement['quantity'] ?>
                                                <?= h($movement['unit']) ?>
                                            </strong>

                                            <?php if ($movement['kind'] === 'disposal'): ?>

                                                <span class="disposed-badge">
                                                    entsorgt
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= h(
                                                formatDate(
                                                    $movement['expiry_date']
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= h($movement['location_name']) ?>
                                        </td>

                                        <td class="undo-cell">
                                            <?= renderUndoForm(
                                                $movement['kind'] === 'disposal' ? 'undo_disposal' : 'undo_issue',
                                                [
                                                    'article_id' => (int) $movement['article_id'],
                                                    'batch_id' => (int) $movement['batch_id'],
                                                    'location_id' => (int) $movement['location_id'],
                                                ],
                                                (int) $movement['quantity'],
                                                (int) $movement['quantity'] . ' ' . $movement['unit'] . ' '
                                                    . $movement['article_name'] . ' wieder in '
                                                    . $movement['location_name'] . ' einbuchen'
                                                    . ($movement['kind'] === 'disposal' ? ' (Entsorgung rückgängig)?' : '?')
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($todayTransfers): ?>

                <h2 class="today-section-title">
                    Heute umgebucht
                </h2>

                <div class="card">

                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>
                                    <th>Artikel</th>
                                    <th>Menge</th>
                                    <th>MHD</th>
                                    <th>Von → Nach</th>
                                    <th>Rückgängig</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($todayTransfers as $transfer): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= h($transfer['article_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= (int) $transfer['quantity'] ?>
                                                <?= h($transfer['unit']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(formatDate($transfer['expiry_date'])) ?>
                                        </td>

                                        <td>
                                            <?= h($transfer['from_location_name']) ?>
                                            →
                                            <?= h($transfer['to_location_name']) ?>
                                        </td>

                                        <td class="undo-cell">
                                            <?= renderUndoForm(
                                                'undo_transfer',
                                                [
                                                    'article_id' => (int) $transfer['article_id'],
                                                    'batch_id' => (int) $transfer['batch_id'],
                                                    'location_id' => (int) $transfer['from_location_id'],
                                                    'to_location_id' => (int) $transfer['to_location_id'],
                                                ],
                                                (int) $transfer['quantity'],
                                                (int) $transfer['quantity'] . ' ' . $transfer['unit'] . ' '
                                                    . $transfer['article_name'] . ' von ' . $transfer['to_location_name']
                                                    . ' zurück nach ' . $transfer['from_location_name'] . ' buchen?'
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endif; ?>


        </div>

    <?php elseif ($page === 'expiry'): ?>

        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>MHD-Übersicht</h1>

                    <p>
                        Bereits abgelaufenes und in den nächsten
                        90 Tagen ablaufendes Material, über alle
                        Lagerorte hinweg.
                    </p>

                </div>

                <div class="actions">

                    <a
                        href="?page=expiry"
                        class="button"
                    >
                        Aktualisieren
                    </a>

                </div>

            </div>

            <div class="article-top-row">

                <div class="card">

                    <div class="today-summary">

                        <strong class="<?= $expiredCount > 0 ? 'stock-low' : '' ?>">
                            <?= $expiredCount ?>
                        </strong>

                        <span>
                            abgelaufen
                        </span>

                    </div>

                </div>

                <div class="card">

                    <div class="today-summary">

                        <strong>
                            <?= $expiringSoonCount ?>
                        </strong>

                        <span>
                            laufen bald ab (90 Tage)
                        </span>

                    </div>

                </div>

            </div>

            <div class="card">

                <?php if (!$expiringBatches): ?>

                    <p class="empty-state compact">
                        Kein Material läuft in den nächsten 90 Tagen ab.
                    </p>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="table-with-article-number">

                            <thead>

                                <tr>

                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Lagerort</th>
                                    <th>MHD</th>
                                    <th>Menge</th>
                                    <th></th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($expiringBatches as $row): ?>

                                    <?php
                                    $rowExpiry = expiryInfo(
                                        $row['expiry_date']
                                    );
                                    ?>

                                    <tr>

                                        <td>
                                            <a
                                                href="?page=article&id=<?= (int) $row['article_id'] ?>"
                                                class="article-link"
                                            >
                                                <strong>
                                                    <?= h($row['article_name']) ?>
                                                </strong>
                                            </a>
                                        </td>

                                        <td>
                                            <?= h($row['article_number'] ?? '') ?>
                                        </td>

                                        <td>
                                            <a href="?page=location&id=<?= (int) $row['location_id'] ?>">
                                                <?= h($row['location_name']) ?>
                                            </a>
                                        </td>

                                        <td>

                                            <strong class="<?= $rowExpiry['class'] ?>">
                                                <?= h(formatDate($row['expiry_date'])) ?>
                                            </strong>

                                            <?php if ($rowExpiry['warning']): ?>

                                                <span class="warning">
                                                    <?= h($rowExpiry['warning']) ?>
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <strong>
                                                <?= (int) $row['quantity'] ?>
                                                <?= h($row['unit']) ?>
                                            </strong>
                                        </td>

                                        <td>

                                            <?php if ($rowExpiry['class'] === 'expiry-expired'): ?>

                                                <?= renderDisposeForm(
                                                    (int) $row['article_id'],
                                                    (int) $row['batch_id'],
                                                    (int) $row['location_id'],
                                                    $row['article_name'] . ': ' . (int) $row['quantity'] . ' ' . $row['unit']
                                                        . ' (MHD ' . formatDate($row['expiry_date']) . ') aus '
                                                        . $row['location_name'] . ' entsorgen?',
                                                    'expiry'
                                                ) ?>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    <?php elseif ($page === 'issue'): ?>

        <div class="issue-page">

            <div class="page-header">

                <div>

                    <h1>Buchen</h1>

                    <p>
                        Artikelnummer scannen oder eingeben, Von und
                        Nach wählen. Es wird automatisch ein
                        Stück mit dem ältesten MHD ausgebucht oder an
                        den gewählten Lagerort umgebucht.
                    </p>

                </div>

            </div>

            <div class="card issue-card">

                <div class="issue-toolbar">

                    <button
                        type="button"
                        class="button button-primary issue-scan-button"
                        id="issue-start-scan"
                        hidden
                    >
                        Scanner starten
                    </button>

                    <?php /* Richtung der Buchung, gut sichtbar direkt über dem Kamerabild – wird von booking.js aktualisiert. */ ?>
                    <div
                        id="issue-mode"
                        class="issue-mode <?= $issueTarget === 'issue' ? 'issue-mode-issue' : 'issue-mode-transfer' ?>"
                        aria-live="polite"
                    ><?= h($issueModeText) ?></div>

                </div>

                <div
                    id="issue-scanner"
                    class="issue-scanner"
                    hidden
                ></div>

                <div
                    id="issue-scan-status"
                    class="issue-scan-status"
                    hidden
                ></div>

                <div
                    id="issue-scan-result"
                    class="scan-result"
                    hidden
                ></div>

                <form
                    method="post"
                    class="issue-form"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="issue"
                    >

                    <label class="issue-article-field">

                        <span>
                            Artikelnummer
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            id="issue-article-number"
                            autocomplete="off"
                            spellcheck="false"
                            autofocus
                            required
                        >

                    </label>

                    <label class="issue-target-field">

                        <span>
                            Von
                        </span>

                        <select
                            name="source"
                            id="issue-source"
                        >

                            <?php foreach ($allLocations as $sourceLocation): ?>

                                <option
                                    value="<?= (int) $sourceLocation['id'] ?>"
                                    <?= (int) $sourceLocation['id'] === $issueSourceId ? 'selected' : '' ?>
                                >
                                    <?= h($sourceLocation['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <label class="issue-target-field">

                        <span>
                            Nach
                        </span>

                        <select
                            name="target"
                            id="issue-target"
                        >

                            <option value="issue" <?= $issueTarget === 'issue' ? 'selected' : '' ?>>
                                Ausbuchen
                            </option>

                            <?php foreach ($allLocations as $targetLocation): ?>

                                <option
                                    value="<?= (int) $targetLocation['id'] ?>"
                                    <?= (string) $targetLocation['id'] === $issueTarget ? 'selected' : '' ?>
                                >
                                    <?= h($targetLocation['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <button
                        type="submit"
                        class="button button-primary"
                        id="issue-submit"
                    >
                        Ausbuchen
                    </button>

                </form>

            </div>

        </div>

    <?php elseif ($page === 'label' && $article): ?>

    <div class="label-print-page">

        <?php for ($i = 0; $i < 8; $i++): ?>

            <div class="label">

                <div class="label-category">
                    <?= h($article['category_name'] ?? 'Sonstiges') ?>
                </div>

                <div class="label-content">

                    <div class="label-text">

                        <div class="label-name">
                            <?= h($article['name']) ?>
                        </div>

                        <?php if (!empty($article['article_number'])): ?>

                            <div class="label-number">
                                <?= h($article['article_number']) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <?php
                    $labelQrCode = null;

                    if (!empty($article['article_number'])) {
                        $labelQrGenerator = new QrCodeGenerator();

                        $labelQrCode = $labelQrGenerator->generate(
                            $article['article_number']
                        );
                    }
                    ?>

                    <?php if ($labelQrCode !== null): ?>

                        <div class="label-qr">
                            <?= $labelQrCode ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endfor; ?>

    </div>

<?php elseif ($page === 'new_article'): ?>

        <div class="new-article-page">

        <div class="page-header">

            <div>

                <h1>Artikel anlegen</h1>

                <p>
                    Neuen Lagerartikel erfassen
                </p>

            </div>

            <a
                href="?page=articles"
                class="button button-secondary"
            >
                Abbrechen
            </a>

        </div>


        <div class="card">

            <form
                method="post"
                class="form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="create_article"
                >

                <div class="form-grid">

                    <label>

                        <span>
                            Artikelname *
                        </span>

                        <input
                            type="text"
                            name="name"
                            required
                            autofocus
                        >

                    </label>


                    <label>

                        <span>
                            Artikelnummer *
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            id="new-article-number"
                            required
                        >

                        <small class="form-hint">
                            Vorschlag wird aus Kategorie und Artikelname erzeugt.
                        </small>

                    </label>



                <label>

                    <span>
                        Kategorie
                    </span>

                    <select
                        name="category_id"
                        required
                    >

                        <?php foreach ($categoryList as $category): ?>

                            <option
                                value="<?= (int) $category['id'] ?>"
                                data-short-name="<?= h($category['short_name']) ?>"
                                <?= $category['name'] === 'Sonstiges' ? 'selected' : '' ?>
                            >
                                <?= h($category['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>


                <label>

                    <span>
                        Einheit
                        </span>

                        <input
                            type="text"
                            name="unit"
                            value="Stück"
                        >

                    </label>

                </div>


                <label>

                    <span>
                        Beschreibung
                    </span>

                    <textarea
                        name="description"
                        rows="3"
                    ></textarea>

                </label>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Artikel anlegen
                    </button>

                    <a
                        href="?page=articles"
                        class="button button-secondary"
                    >
                        Abbrechen
                    </a>

                </div>

            </form>

        </div>

        </div>

    <?php elseif ($page === 'article' && $article): ?>

        <div class="article-detail-page">

        <?php

        $articleSummary = $stock->getStockSummary(
            (int) $article['id']
        );

        $totalStock = $articleSummary['total'];

        $qrCode = null;

        if (!empty($article['article_number'])) {
            $qrGenerator = new QrCodeGenerator();
            $qrCode = $qrGenerator->generate(
                $article['article_number']
            );
        }

        $isLow = $articleSummary['is_low'];

        /*
         * Standard-Lagerort: der erste der festgelegten Reihenfolge
         * ($locations ist danach sortiert), siehe
         * LocationRepository::defaultLocation().
         */
        $mainLocationId = $locations ? (int) $locations[0]['id'] : null;

        /*
         * "Bestand buchen": Von/Nach der letzten Buchung beibehalten
         * (kommen per Redirect, siehe StockActions::stockMove()), sonst
         * Einlagern in den Standard-Lagerort. "receipt" = Einlagern (Von),
         * "issue" = Ausbuchen (Nach), sonst Lagerort-ID.
         */
        $locationIds = array_map('strval', array_column($locations, 'id'));

        $stockFormFrom = in_array($_GET['from'] ?? '', ['receipt', ...$locationIds], true)
            ? $_GET['from']
            : 'receipt';

        $stockFormTo = in_array($_GET['to'] ?? '', ['issue', ...$locationIds], true)
            ? $_GET['to']
            : (string) $mainLocationId;

        /*
         * Bestand je Charge ("none" = ohne MHD) und Lagerort, damit die
         * MHD-Auswahl beim Ausbuchen/Umbuchen zeigt, was am gewählten
         * Lagerort tatsächlich liegt (siehe stock-form.js).
         */
        $batchStockByLocation = [];

        foreach ($articleBatchesByLocation as $row) {
            $batchKey = $row['batch_id'] === null ? 'none' : (string) (int) $row['batch_id'];
            $batchStockByLocation[$batchKey][(int) $row['location_id']] = (int) $row['quantity'];
        }

        ?>


        <div class="page-header">

            <div>

                <a
                    href="?page=articles"
                    class="back-link"
                >
                    ← Artikel
                </a>

                <h1>
                    <?= h($article['name']) ?>
                </h1>

                <?php if ($article['description']): ?>

                    <p>
                        <?= h($article['description']) ?>
                    </p>

                <?php endif; ?>

            </div>

            <div class="actions">

                <a
                    href="?page=label&id=<?= (int) $article['id'] ?>"
                    class="button"
                    target="_blank"
                >
                    Etikett drucken
                </a>

                <a
                    href="?page=edit_article&id=<?= (int) $article['id'] ?>"
                    class="button"
                >
                    Artikel bearbeiten
                </a>

            </div>

        </div>


        <?php
        /*
         * Kompakte Kopfzeile: kleiner QR-Code (zum Scannen vom Bildschirm,
         * zum Drucken gibt es das Etikett), Artikelnummer und Gesamtbestand
         * in einer Card statt zwei großer Kacheln.
         */
        ?>
        <div class="card article-summary">

            <?php if ($qrCode !== null): ?>

                <div class="article-summary-qr">
                    <?= $qrCode ?>
                </div>

                <div class="article-summary-info">

                    <span class="article-summary-label">
                        Artikelnummer
                    </span>

                    <strong>
                        <?= h($article['article_number']) ?>
                    </strong>

                    <small>
                        Der QR-Code enthält nur die Artikelnummer.
                    </small>

                </div>

            <?php else: ?>

                <div class="article-summary-info">

                    <span class="article-summary-label">
                        Artikelnummer
                    </span>

                    <strong>
                        keine
                    </strong>

                    <small>
                        Ohne Artikelnummer gibt es keinen QR-Code, der Artikel
                        kann nicht gescannt werden.
                    </small>

                </div>

            <?php endif; ?>

            <div class="article-summary-stock">

                <span class="article-summary-label">
                    Gesamtbestand
                </span>

                <strong class="<?= $isLow ? 'stock-low' : '' ?>">
                    <?= $totalStock ?>
                    <?= h($article['unit']) ?>
                </strong>

            </div>

        </div>

        <div class="card">

            <div class="card-header">

                <h2>
                    Bestand nach Lagerort
                </h2>

            </div>

            <form
                method="post"
                class="form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="set_article_minimums"
                >

                <input
                    type="hidden"
                    name="article_id"
                    value="<?= (int) $article['id'] ?>"
                >

                <table>

                    <thead>
                        <tr>
                            <th>Lagerort</th>
                            <th>Bestand</th>
                            <th>Mindestbestand</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($articleStock as $location): ?>

                            <?php
                            $locationId = (int) $location['location_id'];

                            $locationQuantity =
                                (int) $location['quantity'];

                            $locationMinimum = $location['minimum_stock'] !== null
                                ? (int) $location['minimum_stock']
                                : null;

                            $locationIsLow = $locationMinimum !== null
                                && (int) $location['usable_quantity'] < $locationMinimum;
                            ?>

                            <tr>

                                <td>
                                    <a href="?page=location&id=<?= (int) $location['location_id'] ?>">
                                        <strong>
                                            <?= h($location['location_name']) ?>
                                        </strong>
                                    </a>
                                </td>

                                <td class="<?= ($locationQuantity < 0 || $locationIsLow) ? 'stock-low' : '' ?>">
                                    <?= $locationQuantity ?>
                                    <?= h($article['unit']) ?>
                                </td>

                                <td>

                                    <div class="minimum-stock-field">

                                        <input
                                            type="number"
                                            min="0"
                                            class="minimum-stock-input"
                                            name="minimum_stock[<?= $locationId ?>]"
                                            placeholder="optional"
                                            value="<?= $locationMinimum !== null ? $locationMinimum : '' ?>"
                                        >

                                        <?= h($article['unit']) ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Speichern
                    </button>

                </div>

            </form>

        </div>

        <div class="card">

            <div class="card-header">

                <h2>
                    Bestand nach MHD
                </h2>

            </div>


            <div class="location-list">

                <?php if (!$articleBatchesByLocation): ?>

                    <div class="empty-state compact">
                        Kein Bestand vorhanden.
                    </div>

                <?php endif; ?>

                <?php foreach ($articleBatchesByLocation as $row): ?>

                    <?php
                    $quantity = (int) $row['quantity'];

                    if ($quantity <= 0) {
                        continue;
                    }

                    $expiry = expiryInfo($row['expiry_date']);
                    ?>

                    <div class="location-row">

                        <div>

                            <strong
                                class="<?= $expiry['class'] ?>"
                            >
                                <?= $row['expiry_date']
                                    ? 'MHD: ' . h(formatDate($row['expiry_date']))
                                    : 'Ohne MHD' ?>
                            </strong>

                            <span class="location-hint">
                                <a href="?page=location&id=<?= (int) $row['location_id'] ?>">
                                    <?= h($row['location_name']) ?>
                                </a>
                            </span>

                            <?php if ($expiry['warning']): ?>

                                <span class="warning">
                                    <?= h($expiry['warning']) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="location-row-end">

                            <div class="stock-value">

                                <?= $quantity ?>
                                <?= h($article['unit']) ?>

                            </div>

                            <?php if ($expiry['class'] === 'expiry-expired'): ?>

                                <?= renderDisposeForm(
                                    (int) $article['id'],
                                    (int) $row['batch_id'],
                                    (int) $row['location_id'],
                                    $article['name'] . ': ' . $quantity . ' ' . $article['unit']
                                        . ' (MHD ' . formatDate($row['expiry_date']) . ') aus '
                                        . $row['location_name'] . ' entsorgen?',
                                    'article'
                                ) ?>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <div class="card">

            <div class="card-header">

                <h2>
                    Bestand buchen
                </h2>

            </div>


            <form
                method="post"
                class="form stock-form"
                id="stock-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="stock_move"
                >

                <input
                    type="hidden"
                    name="article_id"
                    value="<?= (int) $article['id'] ?>"
                >


                <div class="form-grid">

                    <?php /* Der Vorgang ergibt sich aus Von/Nach, siehe StockActions::stockMove(). */ ?>
                    <label>

                        <span>
                            Von
                        </span>

                        <select
                            name="from"
                            id="stock_from"
                            required
                        >

                            <option value="receipt" <?= $stockFormFrom === 'receipt' ? 'selected' : '' ?>>
                                Einlagern
                            </option>

                            <?php foreach ($locations as $location): ?>

                                <option
                                    value="<?= (int) $location['id'] ?>"
                                    <?= $stockFormFrom === (string) $location['id'] ? 'selected' : '' ?>
                                >
                                    <?= h($location['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>


                    <label>

                        <span>
                            Nach
                        </span>

                        <select
                            name="to"
                            id="stock_to"
                            required
                        >

                            <option value="issue" <?= $stockFormTo === 'issue' ? 'selected' : '' ?>>
                                Ausbuchen
                            </option>

                            <?php foreach ($locations as $location): ?>

                                <option
                                    value="<?= (int) $location['id'] ?>"
                                    <?= $stockFormTo === (string) $location['id'] ? 'selected' : '' ?>
                                >
                                    <?= h($location['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>


                    <label>

                        <span>
                            Menge
                        </span>

                        <input
                            type="number"
                            name="quantity"
                            min="1"
                            step="1"
                            required
                        >

                    </label>


                    <label>

                        <span>
                            MHD
                        </span>

                        <select
                            name="batch_selection"
                            id="batch_selection"
                        >

                            <option
                                value="none"
                                data-label="Ohne MHD"
                                data-stock="<?= h(json_encode((object) ($batchStockByLocation['none'] ?? []))) ?>"
                            >
                                Ohne MHD
                            </option>

                            <?php foreach ($articleBatches as $batch): ?>

                                <?php
                                $batchQuantity =
                                    (int) $batch['quantity'];
                                ?>

                                <option
                                    value="<?= (int) $batch['batch_id'] ?>"
                                    data-quantity="<?= $batchQuantity ?>"
                                    data-label="MHD: <?= h(formatDate($batch['expiry_date'])) ?>"
                                    data-stock="<?= h(json_encode((object) ($batchStockByLocation[(string) (int) $batch['batch_id']] ?? []))) ?>"
                                >
                                    MHD:
                                    <?= formatDate($batch['expiry_date']) ?>
                                    –
                                    Bestand:
                                    <?= $batchQuantity ?>
                                </option>

                            <?php endforeach; ?>

                            <option value="new">
                                Neues MHD
                            </option>

                        </select>

                    </label>

                </div>


                <div
                    id="new-expiry-field"
                    class="conditional-field"
                    hidden
                >

                    <label>

                        <span>
                            Neues MHD
                        </span>

                        <input
                            type="date"
                            name="expiry_date"
                            id="expiry_date"
                        >

                    </label>

                    <p class="form-help">
                        Existiert dieses MHD bereits,
                        wird automatisch der vorhandene Bestand
                        verwendet.
                    </p>

                </div>


                <label>

                    <span>
                        Bemerkung
                    </span>

                    <textarea
                        name="note"
                        rows="2"
                        placeholder="Optional"
                    ></textarea>

                </label>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                        id="submit-stock"
                    >
                        Einlagern
                    </button>

                </div>

            </form>

        </div>

        </div>

    <?php elseif ($page === 'edit_article'): ?>

        <div class="edit-article-page">

        <?php

        $articleId = (int) ($_GET['id'] ?? 0);

        $editArticle =
            $articles->find($articleId);

        if (!$editArticle) {
            redirect('?page=articles');
        }

        $editArticleStock = $stock->getPhysicalStock($articleId);

        ?>

        <div class="page-header">

            <div>

                <h1>
                    Artikel bearbeiten
                </h1>

                <p>
                    <?= h($editArticle['name']) ?>
                </p>

            </div>

            <div class="actions">

                <a
                    href="?page=label&id=<?= (int) $editArticle['id'] ?>"
                    class="button"
                    target="_blank"
                >
                    Etikett drucken
                </a>

                <a
                    href="?page=article&id=<?= (int) $editArticle['id'] ?>"
                    class="button button-secondary"
                >
                    Abbrechen
                </a>

            </div>

        </div>


        <div class="card">

            <form
                method="post"
                class="form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="update_article"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int) $editArticle['id'] ?>"
                >


                <div class="form-grid">

                    <label>

                        <span>
                            Artikelname *
                        </span>

                        <input
                            type="text"
                            name="name"
                            value="<?= h($editArticle['name']) ?>"
                            required
                        >

                    </label>


                    <label>

                        <span>
                            Artikelnummer
                        </span>

                        <input
                            type="text"
                            name="article_number"
                            value="<?= h($editArticle['article_number']) ?>"
                        >

                    </label>



            <label>

                <span>
                    Kategorie
                </span>

                <select
                    name="category_id"
                    required
                >

                    <?php foreach ($categoryList as $category): ?>

                        <option
                            value="<?= (int) $category['id'] ?>"
                            <?= (int) $editArticle['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= h($category['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                    </label>


                    <label>

                        <span>
                            Einheit
                        </span>

                        <input
                            type="text"
                            name="unit"
                            value="<?= h($editArticle['unit']) ?>"
                        >

                    </label>

                </div>


                <label>

                    <span>
                        Beschreibung
                    </span>

                    <textarea
                        name="description"
                        rows="3"
                    ><?= h($editArticle['description']) ?></textarea>

                </label>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Speichern
                    </button>

                    <a
                        href="?page=article&id=<?= (int) $editArticle['id'] ?>"
                        class="button button-secondary"
                    >
                        Abbrechen
                    </a>

                </div>

            </form>

        </div>


        <div class="card danger-zone">

            <div class="card-header">

                <h2>
                    Artikel löschen
                </h2>

            </div>

            <div class="card-body">

                <?php if ($editArticleStock > 0): ?>

                    <p>
                        Löschen ist erst möglich, wenn kein Bestand mehr vorhanden ist.
                        Aktuell:
                        <strong>
                            <?= $editArticleStock ?>
                            <?= h($editArticle['unit']) ?>
                        </strong>
                        (abgelaufene Chargen eingeschlossen) – bitte zuerst
                        <a href="?page=article&id=<?= (int) $editArticle['id'] ?>">auf der Artikelseite</a>
                        ausbuchen oder entsorgen.
                    </p>

                <?php else: ?>

                    <p>
                        Der Artikel wird aus der normalen
                        Artikelübersicht entfernt.
                    </p>

                <?php endif; ?>

                <form
                    method="post"
                    onsubmit="return confirm('Artikel wirklich löschen?');"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="deactivate_article"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int) $editArticle['id'] ?>"
                    >

                    <button
                        type="submit"
                        class="button button-danger"
                        <?= $editArticleStock > 0 ? 'disabled' : '' ?>
                    >
                        Artikel löschen
                    </button>

                </form>

            </div>

        </div>

        </div>


    <?php endif; ?>

</main>

<footer class="app-footer">
    SanLager ·
    <a href="https://github.com/Offi83/sanlager/blob/main/LICENSE">GPL-3.0</a><span class="footer-source"> ·
    <a href="https://github.com/Offi83/sanlager">Quellcode</a></span>
</footer>


<script src="/js/vendor/html5-qrcode.min.js" defer></script>
<script src="/js/sortable-list.js" defer></script>
<script src="/js/booking.js" defer></script>
<script src="/js/stock-form.js" defer></script>
<script src="/js/article-number-suggestion.js" defer></script>
<script src="/js/navigation.js" defer></script>





</body>
</html>
