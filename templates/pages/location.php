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

                <table class="table-with-article-number table-cards">

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
                                        <?= h(formatExpiry(
                                            $row['expiry_date'],
                                            (int) $row['has_expiry'] === 1
                                        )) ?>
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
