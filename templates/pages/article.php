        <div class="article-detail-page">

        <?php

        $articleSummary = $stock->getStockSummary(
            (int) $article['id']
        );

        $totalStock = $articleSummary['total'];

        $qrCode = null;

        if (!empty($article['article_number'])) {
            $qrGenerator = new \LagerApp\QrCodeGenerator();
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
