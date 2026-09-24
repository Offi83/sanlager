        <div class="article-detail-page">

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

                <strong class="<?= $articleSummary['is_low'] ? 'stock-low' : '' ?>">
                    <?= h(quantityText((int) $articleSummary['total'], $article)) ?>
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

                            /*
                             * Die Menge enthält Abgelaufenes, der Mindestbestand
                             * zählt es nicht – ohne Hinweis wirkte "10 Stück" in
                             * Rot bei Soll 10 wie ein Rechenfehler.
                             */
                            $locationExpired = $locationQuantity
                                - (int) $location['usable_quantity'];
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
                                    <?= h(quantityText((int) $locationQuantity, $article)) ?>

                                    <?php if ($locationExpired > 0): ?>
                                        <span class="warning">
                                            davon <?= $locationExpired ?> abgelaufen
                                        </span>
                                    <?php endif; ?>
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

                                        <?php /* Neben dem Mindestbestand: Mehrzahl ("Rollen"). */ ?>
                                        <?= h($article['unit_plural']) ?>

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

        <?php if ($showExpiryCard): ?>

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

                                <?= h(quantityText((int) $quantity, $article)) ?>

                            </div>

                            <?php if ($expiry['class'] === 'expiry-expired'): ?>

                                <?= renderDisposeForm(
                                    (int) $article['id'],
                                    (int) $row['batch_id'],
                                    (int) $row['location_id'],
                                    $article['name'] . ': ' . quantityText((int) $quantity, $article)
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

        <?php endif; ?>

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
                data-has-expiry="<?= $articleHasExpiry ? '1' : '0' ?>"
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

                <?php /* Rückfrage zu ungewöhnlichem MHD bestätigt, siehe stock-form.js. */ ?>
                <input
                    type="hidden"
                    name="confirm_expiry"
                    id="confirm_expiry"
                    value="0"
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


                    <?php
                    /*
                     * Artikel ohne MHD: Auswahl ausgeblendet ("Ohne MHD" bleibt
                     * gewählt). Liegt noch alter Bestand mit MHD da, blendet
                     * stock-form.js sie ein, solange davon am Von-Lagerort
                     * etwas liegt.
                     */
                    ?>
                    <label
                        id="batch-selection-field"
                        <?= $articleHasExpiry ? '' : 'hidden' ?>
                    >

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
                                    data-expired="<?= expiryInfo($batch['expiry_date'])['class'] === 'expiry-expired' ? '1' : '0' ?>"
                                    data-expiry="<?= h($batch['expiry_date']) ?>"
                                    data-label="MHD: <?= h(formatDate($batch['expiry_date'])) ?>"
                                    data-stock="<?= h(json_encode((object) ($batchStockByLocation[(string) (int) $batch['batch_id']] ?? []))) ?>"
                                >
                                    MHD:
                                    <?= h(formatDate($batch['expiry_date'])) ?>
                                    –
                                    Bestand:
                                    <?= $batchQuantity ?>
                                </option>

                            <?php endforeach; ?>

                            <?php if ($articleHasExpiry): ?>
                                <option value="new">
                                    Neues MHD
                                </option>
                            <?php endif; ?>

                        </select>

                    </label>

                </div>


                <?php if ($articleHasExpiry): ?>

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

                <?php endif; ?>


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
