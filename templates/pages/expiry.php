        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>MHD-Übersicht</h1>

                    <p>
                        Bereits abgelaufenes und in den nächsten
                        90 Tagen ablaufendes Material, über alle
                        Lagerorte hinweg.
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
                            laufen bald ab (90 Tage)
                        </span>

                    </div>

                </div>

            </div>

            <div class="card">

                <?php if (!$expiringBatches): ?>

                    <p class="empty-state compact">
                        Kein Material läuft in den nächsten 90 Tagen ab.
                    </p>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="table-with-article-number">

                            <thead>

                                <tr>

                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Lagerort</th>
                                    <th>MHD</th>
                                    <th>Menge</th>
                                    <th></th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($expiringBatches as $row): ?>

                                    <?php
                                    $rowExpiry = expiryInfo(
                                        $row['expiry_date']
                                    );
                                    ?>

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
                                            <a href="?page=location&id=<?= (int) $row['location_id'] ?>">
                                                <?= h($row['location_name']) ?>
                                            </a>
                                        </td>

                                        <td>

                                            <strong class="<?= $rowExpiry['class'] ?>">
                                                <?= h(formatDate($row['expiry_date'])) ?>
                                            </strong>

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
                                                    (int) $row['location_id'],
                                                    $row['article_name'] . ': ' . (int) $row['quantity'] . ' ' . $row['unit']
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

                <?php endif; ?>

            </div>

        </div>
