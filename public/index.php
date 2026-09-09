<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LagerApp\Database;
use LagerApp\ArticleRepository;
use LagerApp\BatchRepository;
use LagerApp\CategoryRepository;
use LagerApp\StockRepository;
use LagerApp\QrCodeGenerator;

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
        | Artikel ausbuchen
        |--------------------------------------------------------------------------
        |
        | Wird sowohl vom manuellen Formular / Hardware-Scanner
        | als auch vom Kamera-Scanner verwendet.
        |
        | ajax=1 liefert JSON zurück.
        | Ohne ajax=1 erfolgt ein normaler Redirect.
        |--------------------------------------------------------------------------
        */
        if ($action === 'issue') {

            $isAjax = ($_POST['ajax'] ?? '') === '1';

            try {

                $articleNumber = trim(
                    $_POST['article_number'] ?? ''
                );

                if ($articleNumber === '') {
                    throw new RuntimeException(
                        'Bitte eine Artikelnummer eingeben oder scannen.'
                    );
                }

                $article = $articles->findByArticleNumber(
                    $articleNumber
                );

                if (!$article) {
                    throw new RuntimeException(
                        'Artikelnummer nicht gefunden: ' .
                        $articleNumber
                    );
                }

                $mainLocation = $db->query(
                    "SELECT id
                     FROM storage_locations
                     WHERE name = 'Hauptlager'
                     AND active = 1
                     LIMIT 1"
                )->fetchColumn();

                if (!$mainLocation) {
                    throw new RuntimeException(
                        'Das Hauptlager wurde nicht gefunden.'
                    );
                }

                $result = $stock->issueOldest(
                    (int) $article['id'],
                    (int) $mainLocation,
                    'Scanner-Ausbuchung'
                );

                $expiryText = $result['expiry_date']
                    ? formatDate($result['expiry_date'])
                    : 'ohne MHD';

                if ($isAjax) {

                    header(
                        'Content-Type: application/json; charset=utf-8'
                    );

                    echo json_encode([
                        'success' => true,
                        'article_name' => $article['name'],
                        'article_number' => $articleNumber,
                        'unit' => $article['unit'],
                        'expiry_date' => $expiryText
                    ]);

                    exit;
                }

                redirect(
                    '?page=issue' .
                    '&success=' . urlencode(
                        $article['name'] .
                        ' – 1 ' .
                        $article['unit'] .
                        ' ausgebucht (' .
                        $expiryText .
                        ')'
                    )
                );

            } catch (Throwable $exception) {

                if ($isAjax) {

                    http_response_code(400);

                    header(
                        'Content-Type: application/json; charset=utf-8'
                    );

                    echo json_encode([
                        'success' => false,
                        'error' => $exception->getMessage()
                    ]);

                    exit;
                }

                throw $exception;
            }
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
$todayIssues = [];
$todayIssueCount = 0;

if ($page === 'today_issues') {

    $todayIssues = $stock->getTodayIssues();
    $todayIssueCount = $stock->getTodayIssueCount();
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

    <title>DRK Lager-App</title>

    <link
        rel="stylesheet"
        href="/css/app.css"
    >

</head>

<body>

<header class="topbar">

    <div class="topbar-inner">

<a href="?page=articles" class="brand"> <img src="/images/Logo_DRK_Bereitschaften_RGB.png" alt="DRK Bereitschaften" > <div class="brand-text"> <strong>DRK Lager-App</strong> <span>Sanitätslager</span> </div> </a> <nav> <a href="?page=articles" class="active"> Artikel </a> <a href="?page=issue"> Ausbuchen </a>  <a href="?page=today_issues"> Heute ausgebucht </a> <a href="?page=new_article"> + Artikel </a> </nav>

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

        <div class="card">
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

                    <p class="empty">
                        Heute wurden noch keine Artikel ausgebucht.
                    </p>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table>

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

    <?php elseif ($page === 'issue'): ?>

        <div class="issue-page">

            <div class="page-header">

                <div>

                    <h1>Ausbuchen</h1>

                    <p>
                        Artikelnummer scannen oder eingeben.
                        Es wird automatisch ein Stück mit dem ältesten MHD
                        aus dem Hauptlager entnommen.
                    </p>

                </div>

            </div>

            <?php if (
                isset($_GET['success'])
                && $_GET['success'] !== ''
            ): ?>

                <div class="alert success">
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

                    <button
                        type="submit"
                        class="button button-primary"
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

        $qrCode = null;

        if (!empty($article['article_number'])) {
            $qrGenerator = new QrCodeGenerator();
            $qrCode = $qrGenerator->generate(
                $article['article_number']
            );
        }

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

        </div>


    <?php endif; ?>

</main>


<script src="https://unpkg.com/html5-qrcode" defer></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const scanButton =
        document.getElementById('issue-start-scan');

    const scannerElement =
        document.getElementById('issue-scanner');

    const scanStatus =
        document.getElementById('issue-scan-status');

    const scanResult =
        document.getElementById('issue-scan-result');

    const articleNumber =
        document.getElementById('issue-article-number');


    if (
        !scanButton
        || !scannerElement
        || !scanStatus
        || !scanResult
        || !articleNumber
    ) {
        return;
    }


    let scanner = null;
    let scanning = false;
    let processing = false;

    let lastScannedCode = null;
    let ignoreLastScannedUntil = 0;

    let lastResult = null;
    let lastResultCount = 0;


    function focusArticleNumber() {

        setTimeout(function () {

            articleNumber.focus();
            articleNumber.select();

        }, 50);

    }


    function showStatus(message) {

        scanStatus.textContent = message;
        scanStatus.hidden = false;

    }


    function showResult(message, error = false) {

        scanResult.textContent = message;
        scanResult.hidden = false;

        scanResult.classList.toggle(
            'error',
            error
        );

    }


    async function stopScanner() {

        if (!scanner) {
            return;
        }

        try {

            if (scanning) {
                await scanner.stop();
            }

            scanner.clear();

        } catch (error) {

            console.warn(
                'Scanner konnte nicht gestoppt werden:',
                error
            );

        }

        scanner = null;
        scanning = false;

        scannerElement.hidden = true;

        scanButton.textContent =
            'Scanner starten';

        focusArticleNumber();

    }


    async function startScanner() {

        if (typeof Html5Qrcode === 'undefined') {

            showStatus(
                'Scanner-Bibliothek konnte nicht geladen werden.'
            );

            return;
        }


        scannerElement.hidden = false;

        scanButton.textContent =
            'Scanner beenden';

        showStatus(
            'Kamera wird gestartet …'
        );


        scanner = new Html5Qrcode(
            'issue-scanner'
        );


        try {

            await scanner.start(
                {
                    facingMode: 'environment'
                },
                {
                    fps: 10,
                    qrbox: {
                        width: 250,
                        height: 250
                    }
                },
                async function (decodedText) {

                    if (processing) {
                        return;
                    }


                    const code =
                        decodedText.trim();


                    if (
                        code === lastScannedCode
                        && Date.now() < ignoreLastScannedUntil
                    ) {
                        return;
                    }


                    processing = true;

                    showStatus(
                        'Buchung läuft …'
                    );


                    try {

                        const formData =
                            new FormData();

                        formData.append(
                            'action',
                            'issue'
                        );

                        formData.append(
                            'ajax',
                            '1'
                        );

                        formData.append(
                            'article_number',
                            code
                        );


                        const response =
                            await fetch(
                                window.location.href,
                                {
                                    method: 'POST',
                                    body: formData
                                }
                            );


                        const data =
                            await response.json();


                        if (!data.success) {

                            throw new Error(
                                data.error
                                    || 'Buchung fehlgeschlagen.'
                            );

                        }


                        /*
                         * Gleichen QR-Code für 7 Sekunden
                         * nicht erneut buchen.
                         */
                        lastScannedCode = code;

                        ignoreLastScannedUntil =
                            Date.now() + 7000;


                        /*
                         * Aufeinanderfolgende Buchungen
                         * desselben Artikels zusammenfassen.
                         */
                        if (lastResult === code) {

                            lastResultCount++;

                        } else {

                            lastResult = code;
                            lastResultCount = 1;

                        }


                        showResult(
                            data.article_name
                            + ' – '
                            + lastResultCount
                            + ' '
                            + data.unit
                            + ' ausgebucht – MHD '
                            + data.expiry_date
                        );


                        showStatus(
                            'Bereit für den nächsten Scan.'
                        );


                    } catch (error) {

                        showResult(
                            error.message,
                            true
                        );

                        showStatus(
                            'Fehler – nächster Scan möglich.'
                        );

                    }


                    processing = false;

                },
                function () {
                    // Kein QR-Code erkannt.
                }
            );


            scanning = true;

            showStatus(
                'QR-Code vor die Kamera halten.'
            );


        } catch (error) {

            console.error(
                'Scanner konnte nicht gestartet werden:',
                error
            );

            await stopScanner();

            showStatus(
                'Kamera konnte nicht gestartet werden.'
            );

        }

    }


    scanButton.addEventListener(
        'click',
        async function () {

            if (scanning) {

                await stopScanner();

                showStatus(
                    'Scanner beendet.'
                );

                return;
            }

            await startScanner();

        }
    );


    /*
     * Beim Öffnen der Seite ist das Feld sofort aktiv.
     * Dadurch kann ein Hardware-Barcode-Scanner direkt
     * scannen und mit Enter absenden.
     */
    focusArticleNumber();

});

</script>


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
