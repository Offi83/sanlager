<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LagerApp\Database;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryRepository;
use LagerApp\StockRepository;

$root = dirname(__DIR__);

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

$dbFile = $root . '/' . ($_ENV['DB_DATABASE'] ?? 'database/database.sqlite');

if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0775, true);
}

$database = new Database($dbFile);
$db = $database->connection();

$articles = new ArticleRepository($db);
$batches = new BatchRepository($db);
$categories = new CategoryRepository($db);
$stock = new StockRepository($db);

$categoryList = $categories->all();

function h(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function formatDate(?string $date): string
{
    if (!$date) {
        return 'ohne MHD';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('d.m.Y', $timestamp);
}

function expiryClass(?string $date): string
{
    if (!$date) {
        return '';
    }

    $expiry = strtotime($date);

    if ($expiry === false) {
        return '';
    }

    $today = strtotime(date('Y-m-d'));
    $warning = strtotime('+90 days');

    if ($expiry < $today) {
        return 'expiry-expired';
    }

    if ($expiry <= $warning) {
        return 'expiry-warning';
    }

    return '';
}

function expiryWarning(?string $date): string
{
    if (!$date) {
        return '';
    }

    $expiry = strtotime($date);

    if ($expiry === false) {
        return '';
    }

    $today = strtotime(date('Y-m-d'));
    $warning = strtotime('+90 days');

    if ($expiry < $today) {
        return 'ABGELAUFEN';
    }

    if ($expiry <= $warning) {
        return 'MHD bald erreicht';
    }

    return '';
}

$page = $_GET['page'] ?? 'articles';
$action = $_POST['action'] ?? null;

$error = null;
$message = null;

/*
|--------------------------------------------------------------------------
| POST-Aktionen
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | Artikel anlegen
        |--------------------------------------------------------------------------
        */
        if ($action === 'create_article') {

            $articleNumber = trim($_POST['article_number'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unit = trim($_POST['unit'] ?? 'Stück');
            $minimumStock = max(
                0,
                (int) ($_POST['minimum_stock'] ?? 0)
            );

            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($categoryId <= 0 || !$categories->find($categoryId)) {
                throw new RuntimeException(
                    'Bitte eine gültige Kategorie auswählen.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Bitte einen Artikelnamen eingeben.'
                );
            }

            $articles->create(
                $articleNumber !== '' ? $articleNumber : null,
                $name,
                $description,
                $unit !== '' ? $unit : 'Stück',
                $minimumStock,
                $categoryId
            );

            redirect('?page=articles&message=Artikel+angelegt');
        }

        /*
        |--------------------------------------------------------------------------
        | Artikel bearbeiten
        |--------------------------------------------------------------------------
        */
        if ($action === 'update_article') {

            $id = (int) ($_POST['id'] ?? 0);

            $articleNumber = trim($_POST['article_number'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $unit = trim($_POST['unit'] ?? 'Stück');
            $minimumStock = max(
                0,
                (int) ($_POST['minimum_stock'] ?? 0)
            );

            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültiger Artikel.'
                );
            }

            if ($categoryId <= 0 || !$categories->find($categoryId)) {
                throw new RuntimeException(
                    'Bitte eine gültige Kategorie auswählen.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Bitte einen Artikelnamen eingeben.'
                );
            }

            $articles->update(
                $id,
                $articleNumber !== '' ? $articleNumber : null,
                $name,
                $description,
                $unit !== '' ? $unit : 'Stück',
                $minimumStock,
                $categoryId
            );

            redirect(
                '?page=article&id=' . $id .
                '&message=Artikel+gespeichert'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Artikel deaktivieren
        |--------------------------------------------------------------------------
        */
        if ($action === 'deactivate_article') {

            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültiger Artikel.'
                );
            }

            $articles->deactivate($id);

            redirect('?page=articles&message=Artikel+gelöscht');
        }

        /*
        |--------------------------------------------------------------------------
        | Bestand buchen
        |--------------------------------------------------------------------------
        */
        if ($action === 'stock_move') {

            $articleId = (int) ($_POST['article_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $movementType = $_POST['movement_type'] ?? '';

            if ($articleId <= 0) {
                throw new RuntimeException(
                    'Bitte einen Artikel auswählen.'
                );
            }

            if ($locationId <= 0) {
                throw new RuntimeException(
                    'Bitte einen Lagerort auswählen.'
                );
            }

            if ($quantity <= 0) {
                throw new RuntimeException(
                    'Die Menge muss größer als 0 sein.'
                );
            }

            if (!in_array(
                $movementType,
                ['receipt', 'issue'],
                true
            )) {
                throw new RuntimeException(
                    'Ungültiger Vorgang.'
                );
            }

            /*
             * MHD-Auswahl:
             *
             * none = ohne MHD
             * ID   = vorhandenes MHD
             * new  = neues MHD
             */
            $batchSelection =
                $_POST['batch_selection'] ?? 'none';

            $batchId = null;

            /*
             * Neues MHD
             */
            if ($batchSelection === 'new') {

                if ($movementType !== 'receipt') {
                    throw new RuntimeException(
                        'Bei einer Entnahme kann kein neues MHD angelegt werden.'
                    );
                }

                $expiryDate = trim(
                    $_POST['expiry_date'] ?? ''
                );

                if ($expiryDate === '') {
                    throw new RuntimeException(
                        'Bitte ein MHD eingeben.'
                    );
                }

                $batchId = $batches->findOrCreate(
                    $articleId,
                    $expiryDate
                );
            }

            /*
             * Vorhandenes MHD
             */
            elseif ($batchSelection !== 'none') {

                $batchId = (int) $batchSelection;

                if ($batchId <= 0) {
                    throw new RuntimeException(
                        'Ungültige MHD-Auswahl.'
                    );
                }

                $batch = $batches->find($batchId);

                if (!$batch) {
                    throw new RuntimeException(
                        'Das ausgewählte MHD wurde nicht gefunden.'
                    );
                }

                if ((int) $batch['article_id'] !== $articleId) {
                    throw new RuntimeException(
                        'Das MHD gehört nicht zu diesem Artikel.'
                    );
                }
            }

            $note = trim(
                $_POST['note'] ?? ''
            );

            $stock->move(
                $articleId,
                $locationId,
                $quantity,
                $movementType,
                $note !== '' ? $note : null,
                $batchId
            );

            redirect(
                '?page=article&id=' .
                $articleId .
                '&message=' .
                urlencode(
                    $movementType === 'receipt'
                        ? 'Bestand eingelagert'
                        : 'Bestand entnommen'
                )
            );
        }

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
$locations = [];

if ($page === 'article') {

    $articleId = (int) ($_GET['id'] ?? 0);

    if ($articleId <= 0) {
        redirect('?page=articles');
    }

    $article = $articles->find($articleId);

    if (!$article) {
        redirect('?page=articles');
    }

    $articleStock = $stock->getStockForArticle(
        $articleId
    );

    $articleBatches = $stock->getStockByBatch(
        $articleId
    );

    $locations = $stock->locations();
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

    <title>DRK Lager-App</title>

    <link
        rel="stylesheet"
        href="/css/app.css"
    >

</head>

<body>

<header class="topbar">

    <div class="topbar-inner">

        <div class="brand">
            <strong>DRK Lager-App</strong>
            <span>Sanitätslager</span>
        </div>

        <nav>

            <a
                href="?page=articles"
                class="<?= $page === 'articles' ? 'active' : '' ?>"
            >
                Artikel
            </a>

            <a
                href="?page=new_article"
                class="<?= $page === 'new_article' ? 'active' : '' ?>"
            >
                + Artikel
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


    <?php if ($page === 'articles'): ?>

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

                <table>

                    <thead>

                    <tr>
                        <th>Artikel</th>
                        <th>Artikelnummer</th>
                        <th>Einheit</th>
                        <th>Mindestbestand</th>
                        <th>Aktion</th>
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

                            <tr class="category-row">
                                <th colspan="5">
                                    <?= h($item['category_name'] ?? 'Ohne Kategorie') ?>
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

                        $isLow =
                            $total <
                            (int) $item['minimum_stock'];
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

                            <td>
                                <?= (int) $item['minimum_stock'] ?>
                            </td>

                            <td>

                                <a
                                    class="button button-small"
                                    href="?page=article&id=<?= (int) $item['id'] ?>"
                                >
                                    Bestand:
                                    <strong class="<?= $isLow ? 'stock-low' : '' ?>">
                                        <?= $total ?>
                                    </strong>
                                </a>

                                <?php if ($expiredStock > 0): ?>

                                    <span class="expired-stock-warning">
                                        MHD abgelaufen: <?= $expiredStock ?>
                                        <?= h($item['unit']) ?>
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

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
                            Artikelnummer
                        </span>

                        <input
                            type="text"
                            name="article_number"
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


                    <label>

                        <span>
                            Mindestbestand
                        </span>

                        <input
                            type="number"
                            name="minimum_stock"
                            min="0"
                            value="0"
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

        $minimumStock = (int) $article['minimum_stock'];

        $isLow = $totalStock < $minimumStock;

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
                    href="?page=edit_article&id=<?= (int) $article['id'] ?>"
                    class="button"
                >
                    Artikel bearbeiten
                </a>

            </div>

        </div>


        <div class="stock-summary">

            <div class="stock-total">

                <span>
                    Gesamtbestand
                </span>

                <strong class="<?= $isLow ? 'stock-low' : '' ?>">
                    <?= $totalStock ?>
                    <?= h($article['unit']) ?>
                </strong>

                <?php if ($isLow): ?>

                    <small class="warning">
                        Mindestbestand:
                        <?= $minimumStock ?>
                    </small>

                <?php endif; ?>

            </div>


            <div class="stock-minimum">

                <span>
                    Mindestbestand
                </span>

                <strong>
                    <?= $minimumStock ?>
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


            <div class="location-list">

                <?php foreach ($articleStock as $location): ?>

                    <?php
                    $locationQuantity =
                        (int) $location['quantity'];
                    ?>

                    <div class="location-row">

                        <div>

                            <strong>
                                <?= h($location['location_name']) ?>
                            </strong>

                        </div>

                        <div
                            class="stock-value <?= $locationQuantity < 0 ? 'stock-low' : '' ?>"
                        >
                            <?= $locationQuantity ?>
                            <?= h($article['unit']) ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>


        <div class="card">

            <div class="card-header">

                <h2>
                    Bestand nach MHD
                </h2>

            </div>


            <div class="location-list">

                <?php

                $unbatchedStock =
                    $stock->getStockAtLocation(
                        (int) $article['id'],
                        $mainLocationId ?? 0,
                        null
                    );

                ?>


                <?php if ($unbatchedStock > 0): ?>

                    <div class="location-row">

                        <div>

                            <strong>
                                Ohne MHD
                            </strong>

                        </div>

                        <div class="stock-value">

                            <?= $unbatchedStock ?>
                            <?= h($article['unit']) ?>

                        </div>

                    </div>

                <?php endif; ?>


                <?php foreach ($articleBatches as $batch): ?>

                    <?php
                    $quantity =
                        (int) $batch['quantity'];

                    if ($quantity <= 0) {
                        continue;
                    }

                    $warning =
                        expiryWarning(
                            $batch['expiry_date']
                        );
                    ?>

                    <div class="location-row">

                        <div>

                            <strong
                                class="<?= expiryClass($batch['expiry_date']) ?>"
                            >
                                MHD:
                                <?= formatDate($batch['expiry_date']) ?>
                            </strong>

                            <?php if ($warning): ?>

                                <span class="warning">
                                    <?= h($warning) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="stock-value">

                            <?= $quantity ?>
                            <?= h($article['unit']) ?>

                        </div>

                    </div>

                <?php endforeach; ?>


                <?php
                $hasBatchStock = false;

                foreach ($articleBatches as $batch) {
                    if ((int) $batch['quantity'] > 0) {
                        $hasBatchStock = true;
                        break;
                    }
                }
                ?>


                <?php if (
                    $unbatchedStock <= 0
                    && !$hasBatchStock
                ): ?>

                    <div class="empty-state compact">
                        Kein Bestand vorhanden.
                    </div>

                <?php endif; ?>

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

            <a
                href="?page=article&id=<?= (int) $editArticle['id'] ?>"
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


                    <label>

                        <span>
                            Mindestbestand
                        </span>

                        <input
                            type="number"
                            name="minimum_stock"
                            min="0"
                            value="<?= (int) $editArticle['minimum_stock'] ?>"
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

    <?php endif; ?>

</main>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const movementType =
        document.getElementById('movement_type');

    const batchSelection =
        document.getElementById('batch_selection');

    const newExpiryField =
        document.getElementById('new-expiry-field');

    const expiryInput =
        document.getElementById('expiry_date');

    const submitButton =
        document.getElementById('submit-stock');


    if (
        !movementType
        || !batchSelection
    ) {
        return;
    }


    function updateForm() {

        const movement =
            movementType.value;

        let selection =
            batchSelection.value;


        /*
         * "Neues MHD" nur beim Einlagern erlauben.
         */
        const newOption =
            batchSelection.querySelector(
                'option[value="new"]'
            );

        if (newOption) {

            const isIssue =
                movement === 'issue';

            newOption.disabled = isIssue;
            newOption.hidden = isIssue;

            if (
                isIssue
                && selection === 'new'
            ) {

                batchSelection.value = 'none';

                selection = 'none';
            }

        }


        /*
         * Bei Entnahme nur MHDs
         * mit positivem Bestand anzeigen.
         */
        Array.from(
            batchSelection.options
        ).forEach(function (option) {

            if (
                option.value === ''
                || option.value === 'none'
                || option.value === 'new'
            ) {
                return;
            }

            const quantity =
                parseInt(
                    option.dataset.quantity || '0',
                    10
                );

            option.hidden =
                movement === 'issue'
                && quantity <= 0;
        });


        /*
         * Eingabefeld für neues MHD.
         */
        newExpiryField.hidden =
            !(
                movement === 'receipt'
                && selection === 'new'
            );


        if (expiryInput) {

            expiryInput.required =
                movement === 'receipt'
                && selection === 'new';
        }


        /*
         * Button-Beschriftung.
         */
        if (submitButton) {

            submitButton.textContent =
                movement === 'receipt'
                    ? 'Einlagern'
                    : 'Entnehmen';
        }

    }


    movementType.addEventListener(
        'change',
        updateForm
    );

    batchSelection.addEventListener(
        'change',
        updateForm
    );


    updateForm();

});

</script>

</body>
</html>
