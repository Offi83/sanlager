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

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LagerApp\ArticleActions;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryActions;
use LagerApp\CategoryRepository;
use LagerApp\Database;
use LagerApp\LocationActions;
use LagerApp\LocationRepository;
use LagerApp\QrCodeGenerator;
use LagerApp\StockActions;
use LagerApp\StockRepository;

$root = dirname(__DIR__);

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

/*
 * Zeitzone für alle Datumsberechnungen ("heute", MHD-Ablauf, heutige
 * Ausbuchungen). Ohne diese Einstellung nutzt PHP je nach php.ini UTC,
 * wodurch rund um Mitternacht der falsche Tag als "heute" gelten würde.
 */
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Berlin');

$dbFile = $root . '/' . ($_ENV['DB_DATABASE'] ?? 'database/database.sqlite');

if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0775, true);
}

$database = new Database($dbFile);
$db = $database->connection();

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

/*
|--------------------------------------------------------------------------
| POST-Aktionen
|--------------------------------------------------------------------------
|
| Die eigentliche Validierung und Verarbeitung liegt in den *Actions-
| Klassen unter src/. Jede dispatch()-Methode kümmert sich nur um die
| Aktionen, für die sie zuständig ist, und ignoriert alle anderen.
| RuntimeExceptions (ungültige Eingaben) werden hier zentral abgefangen
| und als $error angezeigt.
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $articleActions->dispatch($action);
        $categoryActions->dispatch($action);
        $locationActions->dispatch($action);
        $stockActions->dispatch($action);

    } catch (Throwable $exception) {

        $error = $exception->getMessage();
    }
}


if (isset($_GET['message'])) {
    $message = $_GET['message'];
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
}

$article = null;
$articleStock = [];
$articleBatches = [];
$articleBatchesByLocation = [];
$locations = [];
$todayIssues = [];
$todayIssueCount = 0;
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

    $todayIssues = $stock->getTodayIssues();
    $todayIssueCount = $stock->getTodayIssueCount();
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

$allLocations = [];

if ($page === 'issue') {

    /*
     * Sowohl "Von" als auch "Ziel" bekommen die volle Liste; welche
     * Kombination gültig ist (Von != Ziel), steuert das Frontend
     * (issue-source-Auswahl blendet die gleiche Option im Ziel-Select
     * aus) und wird zusätzlich serverseitig in StockActions geprüft.
     */
    $allLocations = $locationRepository->all();
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

        <div class="alert alert-success">
            <?= h($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-error">
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
                        Diese Reihenfolge bestimmt auch die Auswahl beim Buchen.
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
                                    <th colspan="4">
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
                        $total = $stock->getTotalStock(
                            (int) $item['id']
                        );

                        $expiredStock = $stock->getExpiredStock(
                            (int) $item['id']
                        );

                        $isLow = $stock->hasLowStockAtAnyLocation(
                            (int) $item['id']
                        );
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
                        Übersicht aller heutigen Ausbuchungen.
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

                </div>

            </div>

            <div class="card">

                <?php if (!$todayIssues): ?>

                    <p class="empty-state compact">
                        Heute wurden noch keine Artikel ausgebucht.
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

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

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
                        Artikelnummer scannen oder eingeben, Von- und
                        Ziel-Lagerort wählen. Es wird automatisch ein
                        Stück mit dem ältesten MHD ausgebucht oder an
                        den gewählten Lagerort umgebucht.
                    </p>

                </div>

            </div>

            <?php if (
                isset($_GET['success'])
                && $_GET['success'] !== ''
            ): ?>

                <div class="alert <?= isset($_GET['expired']) ? 'error' : 'success' ?>">
                    <?= h($_GET['success']) ?>
                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert error">
                    <?= h($error) ?>
                </div>

            <?php endif; ?>

            <div class="card issue-card">

                <button
                    type="button"
                    class="button button-primary issue-scan-button"
                    id="issue-start-scan"
                >
                    Scanner starten
                </button>

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

                    <label>

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
                                    <?= $sourceLocation['name'] === 'Hauptlager' ? 'selected' : '' ?>
                                >
                                    <?= h($sourceLocation['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                    <label class="issue-target-field">

                        <span>
                            Ziel
                        </span>

                        <select
                            name="target"
                            id="issue-target"
                        >

                            <option value="issue" selected>
                                Ausbuchen
                            </option>

                            <?php foreach ($allLocations as $targetLocation): ?>

                                <option value="<?= (int) $targetLocation['id'] ?>">
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

        $totalStock = $stock->getTotalStock(
            (int) $article['id']
        );

        $qrCode = null;

        if (!empty($article['article_number'])) {
            $qrGenerator = new QrCodeGenerator();
            $qrCode = $qrGenerator->generate(
                $article['article_number']
            );
        }

        $isLow = $stock->hasLowStockAtAnyLocation(
            (int) $article['id']
        );

        $mainLocationId = null;

        foreach ($locations as $location) {

            if (
                mb_strtolower(
                    $location['name']
                ) === 'hauptlager'
            ) {
                $mainLocationId = (int) $location['id'];
                break;
            }
        }

        if ($mainLocationId === null && $locations) {
            $mainLocationId = (int) $locations[0]['id'];
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


        <div class="article-top-row<?= $qrCode === null ? ' no-qr' : '' ?>">

            <?php if ($qrCode !== null): ?>

                <div class="card article-qr-card">

                    <div class="card-header">

                        <h2>
                            QR-Code
                        </h2>

                    </div>

                    <div class="article-qr-content">

                        <div class="article-qr-code">
                            <?= $qrCode ?>
                        </div>

                        <div class="article-qr-info">

                            <strong>
                                <?= h($article['name']) ?>
                            </strong>

                            <span>
                                Artikelnummer:
                                <?= h($article['article_number']) ?>
                            </span>

                            <small>
                                Dieser QR-Code enthält ausschließlich
                                die Artikelnummer.
                            </small>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <div class="stock-total">

                <span>
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
                                    <strong>
                                        <?= h($location['location_name']) ?>
                                    </strong>
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
                                <?= h($row['location_name']) ?>
                            </span>

                            <?php if ($expiry['warning']): ?>

                                <span class="warning">
                                    <?= h($expiry['warning']) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="stock-value">

                            <?= $quantity ?>
                            <?= h($article['unit']) ?>

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

                    <label>

                        <span>
                            Vorgang
                        </span>

                        <select
                            name="movement_type"
                            id="movement_type"
                            required
                        >

                            <option value="receipt">
                                Einlagern
                            </option>

                            <option value="issue">
                                Entnehmen
                            </option>

                        </select>

                    </label>


                    <label>

                        <span>
                            Lagerort
                        </span>

                        <select
                            name="location_id"
                            required
                        >

                            <?php foreach ($locations as $location): ?>

                                <option
                                    value="<?= (int) $location['id'] ?>"
                                    <?= $mainLocationId === (int) $location['id'] ? 'selected' : '' ?>
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

                            <option value="none">
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

                <p>
                    Der Artikel wird aus der normalen
                    Artikelübersicht entfernt.
                </p>

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
                    >
                        Artikel löschen
                    </button>

                </form>

            </div>

        </div>

        </div>


    <?php endif; ?>

</main>


<script src="/js/vendor/html5-qrcode.min.js" defer></script>
<script src="/js/sortable-list.js" defer></script>
<script src="/js/booking.js" defer></script>
<script src="/js/stock-form.js" defer></script>
<script src="/js/article-number-suggestion.js" defer></script>





</body>
</html>
