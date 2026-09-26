        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>MHD-Übersicht</h1>

                    <p>
                        Bereits abgelaufenes und in den nächsten
                        <?= $expiryDays ?> Tagen ablaufendes Material, je Lagerort.
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
                            laufen bald ab (<?= $expiryDays ?> Tage)
                        </span>

                    </div>

                </div>

            </div>

            <?php if (!$expiryGroups): ?>

                <div class="card">

                    <p class="empty-state compact">
                        Kein Material läuft in den nächsten <?= $expiryDays ?> Tagen ab.
                    </p>

                </div>

            <?php endif; ?>

            <?php foreach ($expiryGroups as $group): ?>

                <div class="card" id="location-<?= (int) $group['location_id'] ?>">

                    <div class="card-header restock-header">

                        <div>

                            <h2>
                                <a href="?page=location&id=<?= (int) $group['location_id'] ?>">
                                    <?= h($group['location_name']) ?>
                                </a>
                            </h2>

                            <p>
                                <?= h(implode(' · ', array_filter([
                                    $group['expired'] > 0 ? $group['expired'] . ' abgelaufen' : '',
                                    $group['soon'] > 0 ? $group['soon'] . ' ' . ($group['soon'] === 1 ? 'läuft' : 'laufen') . ' bald ab' : '',
                                ]))) ?>
                            </p>

                        </div>

                    </div>

                    <div class="table-wrapper">

                        <table class="table-with-article-number table-cards">

                            <thead>

                                <tr>
                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>MHD</th>
                                    <th>Menge</th>
                                    <th></th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php $currentCategory = null; ?>

                                <?php foreach ($group['items'] as $row): ?>

                                    <?php $rowCategory = $row['category_name'] ?? ''; ?>

                                    <?php if ($currentCategory !== $rowCategory): ?>

                                        <?php $currentCategory = $rowCategory; ?>

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
                                            <strong class="<?= $row['expiry']['class'] ?>">
                                                <?= h(formatDate($row['expiry_date'])) ?>
                                            </strong>

                                            <?php if ($row['expiry']['warning']): ?>

                                                <span class="warning">
                                                    <?= h($row['expiry']['warning']) ?>
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(quantityText((int) $row['quantity'], $row)) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?php if ($row['expiry']['class'] === 'expiry-expired'): ?>
                                                <?= renderDisposeForm(
                                                    (int) $row['article_id'],
                                                    (int) $row['batch_id'],
                                                    (int) $row['location_id'],
                                                    $row['article_name'] . ': ' . quantityText((int) $row['quantity'], $row)
                                                        . ' (MHD ' . formatDate($row['expiry_date']) . ') aus '
                                                        . $row['location_name'] . ' entsorgen?',
                                                    'expiry'
                                                ) ?>
                                            <?php endif; ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>
