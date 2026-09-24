        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>Auffüllen</h1>

                    <p>
                        Was an den Lagerorten unter dem Mindestbestand
                        liegt. Abgelaufenes zählt nicht als vorhanden.
                    </p>

                </div>

                <div class="actions">

                    <a
                        href="?page=restock"
                        class="button"
                    >
                        Aktualisieren
                    </a>

                </div>

            </div>

            <?php if (!$restockGroups): ?>

                <div class="card">

                    <p class="empty-state compact">
                        Alles aufgefüllt – kein Lagerort liegt unter dem
                        Mindestbestand.
                    </p>

                </div>

            <?php endif; ?>

            <?php foreach ($restockGroups as $group): ?>

                <?php /* Anker für den Hinweis auf der Lagerort-Seite. */ ?>
                <div class="card" id="location-<?= (int) $group['location_id'] ?>">

                    <div class="card-header restock-header">

                        <div>

                            <h2>
                                <a href="?page=location&id=<?= (int) $group['location_id'] ?>">
                                    <?= h($group['location_name']) ?>
                                </a>
                            </h2>

                            <p>
                                <?= count($group['items']) === 1
                                    ? '1 Artikel fehlt'
                                    : count($group['items']) . ' Artikel fehlen' ?><?= $group['is_default']
                                    ? ' – nachbestellen'
                                    : '' ?>
                            </p>

                        </div>

                        <?php if (!$group['is_default'] && $restockDefaultLocation && $group['can_transfer']): ?>

                            <a
                                href="?page=issue&source=<?= $restockDefaultId ?>&target=<?= (int) $group['location_id'] ?>"
                                class="button button-secondary"
                            >
                                Aus <?= h($restockDefaultLocation['name']) ?> umbuchen
                            </a>

                        <?php endif; ?>

                    </div>

                    <div class="table-wrapper">

                        <table class="table-with-article-number table-cards">

                            <thead>

                                <tr>

                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Fehlt</th>
                                    <th>Vorhanden / Soll</th>

                                    <?php if (!$group['is_default']): ?>
                                        <th>Im <?= h($restockDefaultLocation['name'] ?? '') ?></th>
                                    <?php endif; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php $currentCategory = null; ?>

                                <?php foreach ($group['items'] as $row): ?>

                                    <?php
                                    /*
                                     * Kategorie-Überschriften wie auf der Lagerort-Seite:
                                     * So lässt sich ein Rucksack Fach für Fach packen.
                                     */
                                    $rowCategory = $row['category_name'] ?? '';
                                    ?>

                                    <?php if ($currentCategory !== $rowCategory): ?>

                                        <?php $currentCategory = $rowCategory; ?>

                                        <tr
                                            class="article-category-row"
                                            style="background-color: <?= h($row['category_color'] ?? '#64748b') ?>;"
                                        >
                                            <th colspan="<?= $group['is_default'] ? 4 : 5 ?>">
                                                <span class="article-category-name">
                                                    <?= h($row['category_name'] ?? 'Ohne Kategorie') ?>
                                                </span>
                                            </th>
                                        </tr>

                                    <?php endif; ?>

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
                                            <strong class="stock-low">
                                                <?= h(quantityText((int) $row['missing_quantity'], $row)) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= (int) $row['usable_quantity'] ?>
                                            / <?= (int) $row['minimum_stock'] ?>
                                        </td>

                                        <?php if (!$group['is_default']): ?>

                                            <td>
                                                <?php if ($row['default_quantity'] >= $row['missing_quantity']): ?>
                                                    <?= h(quantityText((int) $row['default_quantity'], $row)) ?>
                                                <?php elseif ($row['default_quantity'] > 0): ?>
                                                    <span class="stock-low">
                                                        nur <?= h(quantityText((int) $row['default_quantity'], $row)) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="stock-low">nichts</span>
                                                <?php endif; ?>
                                            </td>

                                        <?php endif; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>
