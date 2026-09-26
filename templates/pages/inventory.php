        <div class="page-header">

            <div>

                <a
                    href="?page=location&id=<?= (int) $viewLocation['id'] ?>"
                    class="back-link"
                >
                    ← <?= h($viewLocation['name']) ?>
                </a>

                <h1>Inventur</h1>

                <p>
                    Gezählte Menge je MHD eintragen – vorbelegt ist der erwartete
                    Bestand. Abweichungen werden beim Speichern als Korrektur
                    gebucht (kein Verbrauch).
                </p>

            </div>

            <div class="actions">

                <a
                    href="?page=packlist&id=<?= (int) $viewLocation['id'] ?>"
                    class="button button-secondary"
                >
                    Packliste drucken
                </a>

            </div>

        </div>

        <?php if (!$checklist): ?>

            <div class="card">
                <div class="empty-state compact">
                    Für diesen Lagerort ist nichts hinterlegt: kein Bestand und
                    kein Mindestbestand. Gefundenes über „Buchen“ einlagern.
                </div>
            </div>

        <?php else: ?>

            <form
                method="post"
                class="inventory-form"
            >

                <input type="hidden" name="action" value="inventory">
                <input type="hidden" name="location_id" value="<?= (int) $viewLocation['id'] ?>">

                <div class="card">

                    <table class="table-cards inventory-table">

                        <thead>

                            <tr>
                                <th>Artikel</th>
                                <th>MHD</th>
                                <th>Erwartet</th>
                                <th>Gezählt</th>
                                <th></th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php $currentCategory = null; ?>

                            <?php foreach ($checklist as $row): ?>

                                <?php if ($currentCategory !== ($row['category_name'] ?? '')): ?>

                                    <?php $currentCategory = $row['category_name'] ?? ''; ?>

                                    <tr
                                        class="article-category-row"
                                        style="<?= h(categoryStyle($row['category_color'] ?? null)) ?>"
                                    >
                                        <th colspan="5">
                                            <span class="article-category-name">
                                                <?= h($row['category_name'] ?? 'Ohne Kategorie') ?>
                                            </span>
                                        </th>
                                    </tr>

                                <?php endif; ?>

                                <?php
                                $articleId = (int) $row['article_id'];
                                $hasExpiry = (int) $row['has_expiry'] === 1;
                                $lastBatch = array_key_last($row['batches']);
                                ?>

                                <?php foreach ($row['batches'] as $index => $batch): ?>

                                    <?php $batchExpiry = expiryInfo($batch['expiry_date']); ?>

                                    <tr>

                                        <td>
                                            <strong><?= h($row['article_name']) ?></strong>
                                        </td>

                                        <td>
                                            <span class="<?= $batchExpiry['class'] ?>">
                                                <?= h(formatExpiry($batch['expiry_date'], $hasExpiry)) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= h(quantityText($batch['quantity'], $row)) ?>
                                        </td>

                                        <td>
                                            <input
                                                type="number"
                                                name="count[<?= $articleId ?>][<?= $batch['batch_id'] ?? 'none' ?>]"
                                                value="<?= (int) $batch['quantity'] ?>"
                                                data-expected="<?= (int) $batch['quantity'] ?>"
                                                min="0"
                                                max="9999"
                                                inputmode="numeric"
                                                class="inventory-count"
                                                aria-label="<?= h($row['article_name'] . ', ' . formatExpiry($batch['expiry_date'], $hasExpiry)) ?>: gezählt"
                                            >
                                        </td>

                                        <td>
                                            <?php if ($hasExpiry && $index === $lastBatch): ?>
                                                <?php /* Blendet die Zeile „Gefunden“ ein (inventory.js). */ ?>
                                                <button
                                                    type="button"
                                                    class="button button-secondary inventory-add"
                                                    data-found="found-<?= $articleId ?>"
                                                    hidden
                                                >
                                                    + MHD
                                                </button>
                                            <?php endif; ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if ($hasExpiry): ?>

                                    <?php /* Weitere, bisher nicht erfasste Charge – ohne vorhandene Chargen immer sichtbar. */ ?>
                                    <tr
                                        id="found-<?= $articleId ?>"
                                        class="inventory-found<?= $row['batches'] ? ' inventory-found-optional' : '' ?>"
                                    >

                                        <td>
                                            <strong><?= h($row['article_name']) ?></strong>
                                            <span class="inventory-found-label">
                                                <?= $row['batches'] ? 'weiteres MHD gefunden' : 'nichts erwartet' ?>
                                            </span>
                                        </td>

                                        <td>
                                            <input
                                                type="text"
                                                name="found[<?= $articleId ?>][expiry_date]"
                                                placeholder="TT.MM.JJJJ"
                                                inputmode="numeric"
                                                autocomplete="off"
                                                class="inventory-expiry"
                                                aria-label="<?= h($row['article_name']) ?>: MHD der gefundenen Menge"
                                            >
                                        </td>

                                        <td>–</td>

                                        <td>
                                            <input
                                                type="number"
                                                name="found[<?= $articleId ?>][quantity]"
                                                min="0"
                                                max="9999"
                                                inputmode="numeric"
                                                placeholder="0"
                                                class="inventory-count"
                                                aria-label="<?= h($row['article_name']) ?>: gefundene Menge"
                                            >
                                        </td>

                                        <td></td>

                                    </tr>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <div class="form-actions inventory-actions">

                    <a
                        href="?page=location&id=<?= (int) $viewLocation['id'] ?>"
                        class="button button-secondary"
                    >
                        Abbrechen
                    </a>

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Inventur speichern
                    </button>

                </div>

            </form>

        <?php endif; ?>
