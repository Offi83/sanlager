    <?php if ($labelPrint): ?>

        <?php /* Druckansicht: Leiste wird beim Drucken ausgeblendet (app.css). */ ?>
        <div class="page-header labels-toolbar">

            <div>

                <a
                    href="<?= h('?' . http_build_query(['page' => 'labels', 'qty' => $labelQuantities])) ?>"
                    class="back-link"
                >
                    ← Auswahl ändern
                </a>

                <h1>Etiketten</h1>

                <p>
                    <?= $labelCount ?> <?= $labelCount === 1 ? 'Etikett' : 'Etiketten' ?>
                    auf <?= count($labelSheets) ?> <?= count($labelSheets) === 1 ? 'Bogen' : 'Bögen' ?>
                    (A4, 2 × 4 à 105 × 74 mm), jeweils ab dem ersten Platz eines neuen Bogens.
                    Beim Drucken Skalierung „100 %“ bzw. „Tatsächliche Größe“ wählen.
                </p>

            </div>

            <div class="actions">

                <button
                    type="button"
                    class="button button-primary"
                    onclick="window.print()"
                >
                    Drucken
                </button>

            </div>

        </div>

        <?php foreach ($labelSheets as $labelSheet): ?>
            <?= renderLabelSheet($labelSheet) ?>
        <?php endforeach; ?>

    <?php else: ?>

        <div class="page-header">

            <div>

                <a
                    href="?page=articles"
                    class="back-link"
                >
                    ← Artikel
                </a>

                <h1>Etiketten drucken</h1>

                <p>
                    Anzahl je Artikel eintragen – gedruckt wird auf
                    A4-Bögen mit 2 × 4 Etiketten (105 × 74 mm).
                </p>

            </div>

        </div>

        <form
            method="get"
            class="labels-form"
        >

            <input type="hidden" name="page" value="labels">
            <input type="hidden" name="print" value="1">

            <div class="card">

                <div class="card-body labels-options">

                    <?php /* Wie die Knöpfe je Kategorie, aber über alle Kategorien hinweg (label-selection.js). */ ?>
                    <div class="labels-all">

                        <span>Alle Artikel</span>

                        <div class="labels-all-buttons">

                            <button
                                type="button"
                                class="button button-secondary"
                                data-label-all="1"
                            >
                                alle 1×
                            </button>

                            <button
                                type="button"
                                class="button button-secondary"
                                data-label-all=""
                            >
                                keine
                            </button>

                        </div>

                    </div>

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Etiketten anzeigen
                    </button>

                </div>

            </div>

            <div class="card">

                <?php if (!$labelArticles): ?>

                    <p class="empty-state compact">
                        Noch keine Artikel angelegt.
                    </p>

                <?php else: ?>

                    <table class="labels-table table-with-article-number">

                        <thead>
                            <tr>
                                <th>Artikel</th>
                                <th>Artikelnummer</th>
                                <th>Anzahl</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php $currentCategoryId = null; ?>

                            <?php foreach ($labelArticles as $labelArticle): ?>

                                <?php if ($currentCategoryId !== (int) ($labelArticle['category_id'] ?? 0)): ?>

                                    <?php $currentCategoryId = (int) ($labelArticle['category_id'] ?? 0); ?>

                                    <tr
                                        class="article-category-row"
                                        style="<?= h(categoryStyle($labelArticle['category_color'] ?? null)) ?>"
                                    >
                                        <th colspan="3">
                                            <span class="article-category-name">
                                                <?= h($labelArticle['category_name'] ?? 'Ohne Kategorie') ?>
                                            </span>

                                            <span class="labels-category-actions">
                                                <button
                                                    type="button"
                                                    class="button small"
                                                    data-label-category="<?= $currentCategoryId ?>"
                                                    data-label-quantity="1"
                                                >
                                                    alle 1×
                                                </button>

                                                <button
                                                    type="button"
                                                    class="button small"
                                                    data-label-category="<?= $currentCategoryId ?>"
                                                    data-label-quantity=""
                                                >
                                                    keine
                                                </button>
                                            </span>
                                        </th>
                                    </tr>

                                <?php endif; ?>

                                <tr>

                                    <td>
                                        <strong><?= h($labelArticle['name']) ?></strong>
                                    </td>

                                    <td class="article-number">
                                        <?= h($labelArticle['article_number'] ?? '') ?>
                                    </td>

                                    <td>
                                        <input
                                            type="number"
                                            min="0"
                                            max="99"
                                            class="labels-quantity"
                                            name="qty[<?= (int) $labelArticle['id'] ?>]"
                                            value="<?= isset($labelQuantities[(int) $labelArticle['id']]) ? $labelQuantities[(int) $labelArticle['id']] : '' ?>"
                                            placeholder="0"
                                            aria-label="Anzahl Etiketten für <?= h($labelArticle['name']) ?>"
                                            data-label-category="<?= $currentCategoryId ?>"
                                        >
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

        </form>

    <?php endif; ?>
