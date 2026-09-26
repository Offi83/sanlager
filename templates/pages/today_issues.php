        <div class="today-issues-page">

            <div class="page-header">

                <div>

                    <h1>Heute</h1>

                    <p>
                        Buchungen von heute – Fehlbuchungen lassen sich
                        rückgängig machen.
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

            <div class="today-counts">

                <?php foreach ($todayCounts as $tile): ?>

                    <?php if ($tile['count'] > 0): ?>
                        <a href="#<?= h($tile['id']) ?>" class="card today-count">
                    <?php else: ?>
                        <div class="card today-count today-count-zero">
                    <?php endif; ?>

                        <strong>
                            <?= $tile['count'] ?>
                        </strong>

                        <span>
                            <?= h($tile['count'] === 1 ? $tile['one'] : $tile['many']) ?>
                        </span>

                    <?= $tile['count'] > 0 ? '</a>' : '</div>' ?>

                <?php endforeach; ?>

            </div>

            <?php if (!$todayIssues && !$todayDisposals && !$todayTransfers && !$todayReceipts): ?>

                <div class="card">

                    <p class="empty-state compact">
                        Heute wurde noch nichts gebucht.
                    </p>

                </div>

            <?php endif; ?>

            <?php foreach (['heute-ausgebucht', 'heute-entsorgt', 'heute-umgebucht', 'heute-eingelagert'] as $sectionId): ?>

                <?php if ($sectionId === 'heute-umgebucht'): ?>

                    <?php if (!$todayTransfers) {
                        continue;
                    } ?>

                    <h2 class="today-section-title" id="heute-umgebucht">
                        Heute umgebucht
                    </h2>

                    <div class="card">

                        <div class="table-wrapper">

                            <table class="table-with-article-number table-cards">

                                <thead>

                                    <tr>
                                        <th>Artikel</th>
                                        <th>Artikelnummer</th>
                                        <th>Menge</th>
                                        <th>MHD</th>
                                        <th>Rückgängig</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($todayTransferGroups as $direction => $transfers): ?>

                                        <tr class="table-group-row">
                                            <th colspan="5">
                                                <?= h($direction) ?>
                                            </th>
                                        </tr>

                                        <?php foreach ($transfers as $transfer): ?>

                                            <tr>

                                                <td>
                                                    <strong>
                                                        <?= h($transfer['article_name']) ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    <?= h($transfer['article_number'] ?? '') ?>
                                                </td>

                                                <td>
                                                    <strong>
                                                        <?= h(quantityText((int) $transfer['quantity'], $transfer)) ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    <?= h(formatExpiry(
                                                        $transfer['expiry_date'],
                                                        (int) $transfer['has_expiry'] === 1
                                                    )) ?>
                                                </td>

                                                <td class="undo-cell">
                                                    <?= renderUndoForm(
                                                        'undo_transfer',
                                                        [
                                                            'article_id' => (int) $transfer['article_id'],
                                                            'batch_id' => (int) $transfer['batch_id'],
                                                            'location_id' => (int) $transfer['from_location_id'],
                                                            'to_location_id' => (int) $transfer['to_location_id'],
                                                        ],
                                                        (int) $transfer['quantity'],
                                                        quantityText((int) $transfer['quantity'], $transfer) . ' '
                                                            . $transfer['article_name'] . ' von ' . $transfer['to_location_name']
                                                            . ' zurück nach ' . $transfer['from_location_name'] . ' buchen?'
                                                    ) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                    <?php continue; ?>

                <?php endif; ?>

                <?php
                $section = $todaySections[$sectionId];

                if (!$section['rows']) {
                    continue;
                }
                ?>

                <h2 class="today-section-title" id="<?= h($sectionId) ?>">
                    <?= h($section['title']) ?>
                </h2>

                <div class="card">

                    <div class="table-wrapper">

                        <table class="table-with-article-number table-cards">

                            <thead>

                                <tr>
                                    <th>Artikel</th>
                                    <th>Artikelnummer</th>
                                    <th>Menge</th>
                                    <th>MHD</th>
                                    <th>Lagerort</th>
                                    <th>Rückgängig</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($section['rows'] as $movement): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= h($movement['article_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h($movement['article_number'] ?? '') ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= h(quantityText((int) $movement['quantity'], $movement)) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= h(formatExpiry(
                                                $movement['expiry_date'],
                                                (int) $movement['has_expiry'] === 1
                                            )) ?>
                                        </td>

                                        <td>
                                            <?= h($movement['location_name']) ?>
                                        </td>

                                        <td class="undo-cell">
                                            <?= renderUndoForm(
                                                $section['undo'],
                                                [
                                                    'article_id' => (int) $movement['article_id'],
                                                    'batch_id' => (int) $movement['batch_id'],
                                                    'location_id' => (int) $movement['location_id'],
                                                ],
                                                (int) $movement['quantity'],
                                                $section['question']($movement)
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>
