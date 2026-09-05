<?php

require_once __DIR__ . '/../vendor/autoload.php';

use LagerApp\ArticleRepository;
use LagerApp\Database;
use LagerApp\StockRepository;

$database = new Database(
    __DIR__ . '/../database/database.sqlite'
);

$db = $database->connection();

$articles = new ArticleRepository($db);
$stock = new StockRepository($db);

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$action = $_GET['action'] ?? 'list';
$search = trim($_GET['search'] ?? '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postAction = $_POST['action'] ?? '';

    try {

        /*
         * Artikel anlegen
         */
        if ($postAction === 'create') {

            $name = trim($_POST['name'] ?? '');
            $articleNumber = trim(
                $_POST['article_number'] ?? ''
            );
            $description = trim(
                $_POST['description'] ?? ''
            );
            $unit = trim(
                $_POST['unit'] ?? 'Stück'
            );
            $minimumStock = (int) (
                $_POST['minimum_stock'] ?? 0
            );

            if ($name === '') {
                throw new RuntimeException(
                    'Bitte einen Artikelnamen eingeben.'
                );
            }

            if ($minimumStock < 0) {
                throw new RuntimeException(
                    'Der Mindestbestand darf nicht negativ sein.'
                );
            }

            $articles->create(
                $articleNumber ?: null,
                $name,
                $description,
                $unit ?: 'Stück',
                $minimumStock
            );

            header('Location: ?saved=1');
            exit;
        }


        /*
         * Artikel bearbeiten
         */
        if ($postAction === 'update') {

            $id = (int) ($_POST['id'] ?? 0);

            $name = trim($_POST['name'] ?? '');
            $articleNumber = trim(
                $_POST['article_number'] ?? ''
            );
            $description = trim(
                $_POST['description'] ?? ''
            );
            $unit = trim(
                $_POST['unit'] ?? 'Stück'
            );
            $minimumStock = (int) (
                $_POST['minimum_stock'] ?? 0
            );

            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültiger Artikel.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Bitte einen Artikelnamen eingeben.'
                );
            }

            if ($minimumStock < 0) {
                throw new RuntimeException(
                    'Der Mindestbestand darf nicht negativ sein.'
                );
            }

            $articles->update(
                $id,
                $articleNumber ?: null,
                $name,
                $description,
                $unit ?: 'Stück',
                $minimumStock
            );

            header(
                'Location: ?action=edit&id=' . $id . '&saved=1'
            );
            exit;
        }


        /*
         * Artikel deaktivieren
         */
        if ($postAction === 'deactivate') {

            $id = (int) ($_POST['id'] ?? 0);

            if ($id > 0) {
                $articles->deactivate($id);
            }

            header('Location: ?deactivated=1');
            exit;
        }


        /*
         * Bestand buchen
         */
        if ($postAction === 'stock_move') {

            $articleId = (int) (
                $_POST['article_id'] ?? 0
            );

            $locationId = (int) (
                $_POST['location_id'] ?? 0
            );

            $quantity = (int) (
                $_POST['quantity'] ?? 0
            );

            $movementType = $_POST['movement_type'] ?? '';

            $note = trim(
                $_POST['note'] ?? ''
            );

            if ($articleId <= 0) {
                throw new RuntimeException(
                    'Ungültiger Artikel.'
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

            $stock->move(
                $articleId,
                $locationId,
                $quantity,
                $movementType,
                $note ?: null
            );

            header(
                'Location: ?action=stock&id=' . $articleId .
                '&stock_saved=1'
            );

            exit;
        }

    } catch (PDOException $exception) {

        if (
            str_contains(
                strtolower($exception->getMessage()),
                'unique'
            )
        ) {
            $error =
                'Diese Artikelnummer ist bereits vorhanden.';
        } else {
            $error =
                'Der Vorgang konnte nicht gespeichert werden.';
        }

    } catch (RuntimeException $exception) {

        $error = $exception->getMessage();
    }
}


$editArticle = null;

if (
    $action === 'edit' ||
    $action === 'stock'
) {

    $id = (int) ($_GET['id'] ?? 0);

    $editArticle = $articles->find($id);

    if (!$editArticle) {

        $error = 'Artikel nicht gefunden.';
        $action = 'list';

    }
}


$articleList = $articles->all($search);

$locations = $stock->locations();


$defaultLocationId = null;

foreach ($locations as $location) {
    if (
        mb_strtolower(trim($location['name'])) ===
        'hauptlager'
    ) {
        $defaultLocationId = (int) $location['id'];
        break;
    }
}

$articleStock = [];

if ($editArticle) {

    $articleStock =
        $stock->getStockForArticle(
            (int) $editArticle['id']
        );
}

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
        href="css/app.css"
    >

</head>

<body>

<header class="topbar">

    <div>
        <strong>DRK SANITÄTSLAGER</strong>
    </div>

    <nav>
        <a href="./">Artikel</a>
    </nav>

</header>


<main class="container">


<?php if ($error): ?>

    <div class="alert error">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<?php if (isset($_GET['saved'])): ?>

    <div class="alert success">
        Artikel wurde gespeichert.
    </div>

<?php endif; ?>


<?php if (isset($_GET['stock_saved'])): ?>

    <div class="alert success">
        Bestandsänderung wurde gebucht.
    </div>

<?php endif; ?>


<?php if (isset($_GET['deactivated'])): ?>

    <div class="alert success">
        Artikel wurde deaktiviert.
    </div>

<?php endif; ?>


<?php if (
    $action === 'new' ||
    $action === 'edit'
): ?>


<?php
$isEdit = $action === 'edit';
?>


<div class="page-header">

    <div>

        <h1>
            <?= $isEdit
                ? 'Artikel bearbeiten'
                : 'Neuen Artikel anlegen'
            ?>
        </h1>

        <p>
            <?= $isEdit
                ? 'Artikeldaten ändern'
                : 'Material für das Sanitätslager erfassen'
            ?>
        </p>

    </div>

    <a
        class="button secondary"
        href="./"
    >
        Zurück
    </a>

</div>


<form
    method="post"
    class="card form-card"
>

    <input
        type="hidden"
        name="action"
        value="<?= $isEdit
            ? 'update'
            : 'create'
        ?>"
    >


    <?php if ($isEdit): ?>

        <input
            type="hidden"
            name="id"
            value="<?= (int) $editArticle['id'] ?>"
        >

    <?php endif; ?>


    <div class="form-grid">


        <div class="form-group wide">

            <label for="name">
                Artikelname *
            </label>

            <input
                id="name"
                name="name"
                type="text"
                required
                autofocus
                value="<?= e(
                    $editArticle['name'] ?? ''
                ) ?>"
                placeholder="z. B. Mullbinde 8 cm"
            >

        </div>


        <div class="form-group">

            <label for="article_number">
                Artikelnummer
            </label>

            <input
                id="article_number"
                name="article_number"
                type="text"
                value="<?= e(
                    $editArticle['article_number'] ?? ''
                ) ?>"
                placeholder="z. B. SAN-001"
            >

        </div>


        <div class="form-group">

            <label for="unit">
                Einheit *
            </label>

            <select
                id="unit"
                name="unit"
            >

                <?php

                $units = [
                    'Stück',
                    'Packung',
                    'Karton',
                    'Flasche',
                    'Tube',
                    'Rolle',
                    'Paar'
                ];

                ?>

                <?php foreach ($units as $unit): ?>

                    <option
                        value="<?= e($unit) ?>"
                        <?= (
                            ($editArticle['unit']
                                ?? 'Stück'
                            ) === $unit
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($unit) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="form-group">

            <label for="minimum_stock">
                Mindestbestand
            </label>

            <input
                id="minimum_stock"
                name="minimum_stock"
                type="number"
                min="0"
                value="<?= (int) (
                    $editArticle['minimum_stock']
                    ?? 0
                ) ?>"
            >

            <small>
                Bei Unterschreitung wird später gewarnt.
            </small>

        </div>


        <div class="form-group wide">

            <label for="description">
                Beschreibung
            </label>

            <textarea
                id="description"
                name="description"
                rows="4"
                placeholder="Optionale Informationen zum Artikel"
            ><?= e(
                $editArticle['description'] ?? ''
            ) ?></textarea>

        </div>

    </div>


    <div class="form-actions">

        <a
            class="button secondary"
            href="./"
        >
            Abbrechen
        </a>

        <button
            class="button primary"
            type="submit"
        >
            <?= $isEdit
                ? 'Änderungen speichern'
                : 'Artikel anlegen'
            ?>
        </button>

    </div>

</form>


<?php if ($isEdit): ?>

<div class="card danger-card">

    <div>

        <strong>Artikel deaktivieren</strong>

        <p>
            Der Artikel wird nicht gelöscht.
            Er verschwindet lediglich aus der normalen
            Artikelliste.
        </p>

    </div>


    <form method="post">

        <input
            type="hidden"
            name="action"
            value="deactivate"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int) $editArticle['id'] ?>"
        >

        <button
            class="button danger"
            type="submit"
            onclick="return confirm(
                'Artikel wirklich deaktivieren?'
            )"
        >
            Deaktivieren
        </button>

    </form>

</div>

<?php endif; ?>


<?php elseif ($action === 'stock'): ?>


<div class="page-header">

    <div>

        <h1>
            <?= e($editArticle['name']) ?>
        </h1>

        <p>
            Bestand und Lagerorte
        </p>

    </div>

    <a
        class="button secondary"
        href="./"
    >
        Zurück
    </a>

</div>


<?php
$totalStock = $stock->getTotalStock(
    (int) $editArticle['id']
);
?>


<div class="stock-summary">

    <div class="stock-total">

        <span>Gesamtbestand</span>

        <strong>
            <?= $totalStock ?>
            <?= e($editArticle['unit']) ?>
        </strong>

    </div>

    <div class="stock-minimum">

        <span>Mindestbestand</span>

        <strong>
            <?= (int) $editArticle['minimum_stock'] ?>
            <?= e($editArticle['unit']) ?>
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

        <?php foreach ($articleStock as $row): ?>

            <?php
            $quantity = (int) $row['quantity'];
            ?>

            <div class="location-row">

                <span>
                    <?= e($row['location_name']) ?>
                </span>

                <strong>
                    <?= $quantity ?>
                    <?= e($editArticle['unit']) ?>
                </strong>

            </div>

        <?php endforeach; ?>

    </div>

</div>


<div class="card form-card">

    <h2>
        Bestand ändern
    </h2>

    <p class="form-help">
        Jede Änderung wird als Lagerbewegung gespeichert.
    </p>


    <form method="post">

        <input
            type="hidden"
            name="action"
            value="stock_move"
        >

        <input
            type="hidden"
            name="article_id"
            value="<?= (int) $editArticle['id'] ?>"
        >


        <div class="form-grid">


            <div class="form-group">

                <label for="movement_type">
                    Vorgang
                </label>

                <select
                    id="movement_type"
                    name="movement_type"
                >

                    <option value="receipt">
                        Einlagern
                    </option>

                    <option value="issue">
                        Entnehmen
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label for="location_id">
                    Lagerort
                </label>

                <select
                    id="location_id"
                    name="location_id"
                    required
                >

                <?php foreach ($locations as $location): ?>

        <option
            value="<?= (int) $location['id'] ?>"
            <?= (
                (int) $location['id'] ===
                $defaultLocationId
            )
                ? 'selected'
                : ''
            ?>
        >
            <?= e($location['name']) ?>
        </option>
                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label for="quantity">
                    Menge
                </label>

                <input
                    id="quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    required
                    placeholder="z. B. 10"
                >

            </div>


            <div class="form-group">

                <label for="note">
                    Bemerkung
                </label>

                <input
                    id="note"
                    name="note"
                    type="text"
                    placeholder="z. B. Wareneingang"
                >

            </div>

        </div>


        <div class="form-actions">

            <button
                class="button primary"
                type="submit"
            >
                Änderung buchen
            </button>

        </div>

    </form>

</div>


<?php else: ?>


<div class="page-header">

    <div>

        <h1>Artikel</h1>

        <p>
            Verwaltung der Materialien im Sanitätslager
        </p>

    </div>

    <a
        class="button primary"
        href="?action=new"
    >
        + Artikel anlegen
    </a>

</div>


<form
    method="get"
    class="search"
>

    <input
        type="search"
        name="search"
        value="<?= e($search) ?>"
        placeholder="Artikel suchen …"
    >

    <button
        class="button secondary"
        type="submit"
    >
        Suchen
    </button>

    <?php if ($search !== ''): ?>

        <a
            class="button secondary"
            href="./"
        >
            Zurücksetzen
        </a>

    <?php endif; ?>

</form>


<div class="card">

<?php if (count($articleList) === 0): ?>


    <div class="empty">

        <div class="empty-icon">
            📦
        </div>

        <h2>
            Keine Artikel gefunden
        </h2>

        <p>
            <?= $search !== ''
                ? 'Für diese Suche wurden keine Artikel gefunden.'
                : 'Lege deinen ersten Artikel an.'
            ?>
        </p>

        <?php if ($search === ''): ?>

            <a
                class="button primary"
                href="?action=new"
            >
                + Ersten Artikel anlegen
            </a>

        <?php endif; ?>

    </div>


<?php else: ?>


    <div class="table-wrapper">

        <table>

            <thead>

                <tr>

                    <th>Artikel</th>
                    <th>Bestand</th>
                    <th>Artikelnummer</th>
                    <th>Einheit</th>
                    <th></th>

                </tr>

            </thead>


            <tbody>


            <?php foreach ($articleList as $article): ?>

                <?php

                $total =
                    $stock->getTotalStock(
                        (int) $article['id']
                    );

                $minimum =
                    (int) $article['minimum_stock'];

                $lowStock =
                    $total < $minimum;

                ?>

                <tr>

                    <td>

                        <strong>
                            <?= e($article['name']) ?>
                        </strong>

                        <?php if (
                            !empty($article['description'])
                        ): ?>

                            <div class="description">
                                <?= e(
                                    $article['description']
                                ) ?>
                            </div>

                        <?php endif; ?>

                    </td>


                    <td>

                        <span class="stock-value
                            <?= $lowStock
                                ? 'stock-low'
                                : ''
                            ?>"
                        >

                            <?= $total ?>
                            <?= e($article['unit']) ?>

                        </span>

                        <?php if ($lowStock): ?>

                            <span class="warning">
                                ⚠ Mindestbestand
                            </span>

                        <?php endif; ?>

                    </td>


                    <td>
                        <?= e(
                            $article['article_number']
                        ) ?: '–' ?>
                    </td>


                    <td>
                        <?= e($article['unit']) ?>
                    </td>


                    <td class="actions">

                        <a
                            class="button small primary"
                            href="?action=stock&id=<?= (int) $article['id'] ?>"
                        >
                            Bestand
                        </a>

                        <a
                            class="button small secondary"
                            href="?action=edit&id=<?= (int) $article['id'] ?>"
                        >
                            Bearbeiten
                        </a>

                    </td>

                </tr>

            <?php endforeach; ?>


            </tbody>

        </table>

    </div>


<?php endif; ?>

</div>


<?php endif; ?>


</main>

</body>

</html>
